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
        $level = trim(shell_exec("gpioget -c {$this->gpioChip} $pinNumber"));

        return match ($level) {
            "0" => Level::LOW,
            "1" => Level::HIGH,
            default => throw new \Exception("Unknown GPIO level '$level' on line $pinNumber"),
        };
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
            $level !== null ?: Level::LOW
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

        // Kill existing gpioset process for this pin
        if (file_exists($pidFile)) {
            $oldPid = trim(file_get_contents($pidFile));
            if (is_numeric($oldPid)) {
                // Try SIGTERM first (graceful)
                if (posix_kill((int)$oldPid, 0)) {
                    posix_kill((int)$oldPid, SIGTERM);
                    usleep(50000); // 50ms
                    // If still running, force kill
                    if (posix_kill((int)$oldPid, 0)) {
                        posix_kill((int)$oldPid, SIGKILL);
                        usleep(10000); // 10ms
                    }
                }
            }
            @unlink($pidFile);
        }

        // v2: Run in background to hold pin (gpioset holds pin until process exits)
        // Use setsid to create new session and fully detach from parent
        // Write PID to file immediately, then start process
        $pidCmd = sprintf(
            'setsid sh -c "echo \\$\\$ > %s; exec gpioset -c %s %d=%d" </dev/null >/dev/null 2>&1 &',
            $pidFile,
            $this->gpioChip,
            $pinNumber,
            $value
        );
        
        shell_exec($pidCmd);
        
        // Wait for PID file to be created and read it
        $attempts = 0;
        while (!file_exists($pidFile) && $attempts < 10) {
            usleep(10000); // 10ms
            $attempts++;
        }
        
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && is_numeric($pid) && $pid > 0) {
                // Small delay to ensure process starts and pin state changes
                usleep(50000); // 50ms
            }
        }

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

