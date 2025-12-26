<?php

namespace DanJohnson95\Pinout\Shell;

use DanJohnson95\Pinout\Collections\PinCollection;
use DanJohnson95\Pinout\Entities\Pin;
use DanJohnson95\Pinout\Enums\Func;
use DanJohnson95\Pinout\Enums\Level;
use Illuminate\Support\Facades\Cache;

class LibGPIOD implements Commandable
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
        $chip = $this->gpioChip;

        // Check if direction is output - if so, return cached value
        $cmd = "bash -c 'gpioinfo $chip 2>/dev/null | grep -E \"^\\\\s*line\\\\s+$pinNumber:\"'";
        $gpioinfo = @shell_exec($cmd);

        if ($gpioinfo && str_contains($gpioinfo, 'output')) {
            // For output pins, check cache first (they're being held by gpioset)
            try {
                return $this->cache($pinNumber);
            } catch (\Exception $e) {
                // If no cached value, pin might be output but not set yet - default to LOW
                return Level::LOW;
            }
        }

        // Check cache for pins we've set as output (even if gpioinfo doesn't show it)
        $cached = \Illuminate\Support\Facades\Cache::get("gpio.output.$pinNumber", null);
        if ($cached !== null) {
            return $cached;
        }

        // Input — safe to read
        // Capture both stdout and stderr to see actual errors
        $cmd = sprintf('gpioget -c %s %d 2>&1', $chip, $pinNumber);
        $result = @shell_exec($cmd);
        $output = $result ? trim($result) : '';
        
        // Check for error messages in output
        if ($output === '' || str_contains($output, 'cannot find') || str_contains($output, 'error') || str_contains($output, 'Error')) {
            // Pin might not be configured yet or is being held - return LOW as default
            return Level::LOW;
        }
        
        $level = $output;
        
        // Parse gpioget output format: "26"=active or "26"=inactive
        if (str_contains($level, '=active') || preg_match('/"*\d+"*\s*=\s*active/i', $level)) {
            $level = '1';
        } elseif (str_contains($level, '=inactive') || preg_match('/"*\d+"*\s*=\s*inactive/i', $level)) {
            $level = '0';
        }

        return match ($level) {
            "0" => Level::LOW,
            "1" => Level::HIGH,
            default => throw new \Exception("Unknown GPIO level '$level' on line $pinNumber"),
        };
    }

    public function getFunction(int $pinNumber): Func
    {
        $chip = $this->gpioChip;

        $cmd = "bash -c 'gpioinfo $chip 2>/dev/null | grep -E \"^\\\\s*line\\\\s+$pinNumber:\"'";
        $gpioinfo = @shell_exec($cmd);

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
            $chip = $this->gpioChip;
            @shell_exec("gpioget -c $chip $pinNumber 2>/dev/null");
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
        $chip = $this->gpioChip;
        $value = $level === Level::HIGH ? 1 : 0;

        // Kill any existing gpioset process for this pin to avoid accumulation
        $pidFile = "/tmp/pinout_gpioset_$pinNumber.pid";
        if (file_exists($pidFile)) {
            $oldPid = trim(file_get_contents($pidFile));
            if (is_numeric($oldPid) && posix_kill((int)$oldPid, 0)) {
                posix_kill((int)$oldPid, SIGTERM);
            }
            @unlink($pidFile);
        }

        // Use -c flag to specify chip, run in background and save PID
        // gpioset holds the pin HIGH/LOW as long as the process runs
        $cmd = sprintf(
            'gpioset -c %s %d=%d >/dev/null 2>&1 & echo $!',
            $chip,
            $pinNumber,
            $value
        );

        $pid = trim(shell_exec($cmd));
        if ($pid) {
            file_put_contents($pidFile, $pid);
        }

        // Cache the level we set
        $this->cache($pinNumber, $level);
        
        // Small delay to ensure command starts
        usleep(10000); // 10ms

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
