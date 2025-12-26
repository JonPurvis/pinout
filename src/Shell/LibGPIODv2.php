<?php

namespace DanJohnson95\Pinout\Shell;

use DanJohnson95\Pinout\Collections\PinCollection;
use DanJohnson95\Pinout\Entities\Pin;
use DanJohnson95\Pinout\Enums\Func;
use DanJohnson95\Pinout\Enums\Level;
use Illuminate\Support\Facades\Cache;

class LibGPIODv2 implements Commandable
{
    protected ?string $gpioChip;

    public function __construct()
    {
        $this->gpioChip = config('pinout.gpio_chip');
    }

    public function getAll(array $pinNumbers): PinCollection
    {
        $collection = PinCollection::make();

        foreach ($pinNumbers as $pinNumber) {
            $collection->push($this->get($pinNumber));
        }

        return $collection;
    }

    public function get(int $pinNumber): Pin
    {
        return Pin::make(
            pinNumber: $pinNumber,
            level: $this->getLevel($pinNumber),
            func: $this->getFunction($pinNumber)
        );
    }

    protected function getLevel(int $pinNumber): Level
    {
        // Check if direction is output via gpioinfo
        $gpioinfo = shell_exec("gpioinfo -c {$this->gpioChip} | grep -E '^\\s*line\\s+$pinNumber:'");

        if ($gpioinfo && str_contains($gpioinfo, 'output')) {
            // For output pins, return cached value (they're being held by gpioset)
            return $this->cache($pinNumber);
        }

        // Check cache - if we've set this pin as output, use cached value
        // (gpioinfo might not show it as output yet)
        $cached = Cache::get("gpio.output.$pinNumber", null);
        if ($cached !== null) {
            return $cached;
        }

        // Input — safe to read
        $result = shell_exec("gpioget -c {$this->gpioChip} $pinNumber 2>&1");
        $level = trim($result ?: '');

        // Handle various gpioget output formats:
        // - "19"=active or "19"=inactive (with quotes)
        // - active or inactive (without quotes)
        // - 1 or 0 (numeric)
        if (preg_match('/=?(active|1)$/i', $level)) {
            return Level::HIGH;
        }
        if (preg_match('/=?(inactive|0)$/i', $level)) {
            return Level::LOW;
        }
        
        // If output is empty or unrecognized, default to LOW for unconfigured pins
        if (empty($level)) {
            return Level::LOW;
        }
        
        throw new \Exception("Unknown GPIO level '$level' on line $pinNumber");
    }

    public function getFunction(int $pinNumber): Func
    {
        $gpioinfo = shell_exec("gpioinfo -c {$this->gpioChip} | grep -E '^\\s*line\\s+$pinNumber:'");

        if (str_contains($gpioinfo, 'output')) {
            return Func::OUTPUT;
        }

        return Func::INPUT;
    }

    public function setFunction(
        int $pinNumber,
        Func $func,
        ?Level $level = null
    ): self {
        if ($func === Func::INPUT) {
            shell_exec("gpioget -c {$this->gpioChip} $pinNumber");
            return $this;
        }

        return $this->setLevel(
            $pinNumber,
            $level ?? Level::LOW
        );
    }

    public function exportPin(int $pinNumber): void
    {
        // No-op for libgpiod
    }

    public function setLevel(int $pinNumber, Level $level): self
    {
        $value = $level->value;
        $pidFile = "/tmp/pinout_gpioset_$pinNumber.pid";

        // Kill existing gpioset process for this pin (async - don't wait)
        // Kill by PID file first, then by pattern to catch any stragglers
        if (file_exists($pidFile)) {
            $oldPid = trim(file_get_contents($pidFile));
            if (is_numeric($oldPid) && posix_kill((int)$oldPid, 0)) {
                posix_kill((int)$oldPid, SIGTERM);
            }
            @unlink($pidFile);
        }
        
        // Kill any gpioset processes for this pin (async - runs in background)
        shell_exec("pkill -f 'gpioset.*-c {$this->gpioChip}.*$pinNumber=' 2>/dev/null &");

        // Start new gpioset process immediately (don't wait for old one to die)
        // The new process will take over the pin state
        $pidCmd = sprintf(
            'setsid bash -c "echo \\$\\$ > %s; exec nohup gpioset -c %s %d=%d </dev/null >/dev/null 2>&1" &',
            $pidFile,
            $this->gpioChip,
            $pinNumber,
            $value
        );
        
        shell_exec($pidCmd);

        // Update cache immediately - don't wait for PID file or process startup
        $this->cache($pinNumber, $level);
        return $this;
    }

    private function cache(
        int $pinNumber,
        ?Level $value = null
    ): ?Level {
        if ($value === null) {
            $cached = Cache::get("gpio.output.$pinNumber", null);

            if ($cached === null) {
                throw new \Exception("Cannot determine level of output line $pinNumber — no cached value.");
            }
            return $cached;
        }

        Cache::put("gpio.output.$pinNumber", $value);
        return $value;
    }
}

