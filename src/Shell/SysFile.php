<?php

namespace DanJohnson95\Pinout\Shell;

use DanJohnson95\Pinout\Collections\PinCollection;
use DanJohnson95\Pinout\Entities\Pin;
use DanJohnson95\Pinout\Enums\Func;
use DanJohnson95\Pinout\Enums\Level;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SysFile implements Commandable
{
    protected array $exportedPins = [];
    protected string $gpioChip;
    protected array $outputPins = [];
    
    public function __construct()
    {
        $this->gpioChip = config('pinout.gpio_chip', 'gpiochip0');
    }

    /**
     * Sysfs no longer exists; return empty list
     *
     * @return array<int>
     */
    public function getExportedPins(): array
    {
        return [];
    }

    protected function pinIsExported(int $pinNumber): bool
    {
        // libgpiod does not require exporting
        return true;
    }

    public function exportPin(int $pinNumber): void
    {
        // No-op: libgpiod does not use exporting
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

    protected function getFunction(int $pinNumber): Func
    {
        // Track pins we've set as output using cache (persists across commands)
        $outputPins = Cache::get('pinout.output_pins', []);
        if (in_array($pinNumber, $outputPins, true)) {
            return Func::OUTPUT;
        }
        
        // Try to detect using gpioinfo if available
        $chip = $this->gpioChip;
        $cmd = "bash -c 'gpioinfo $chip 2>/dev/null | grep -E \"^\\\\s*line\\\\s+$pinNumber:\"'";
        $gpioinfo = @shell_exec($cmd);
        
        if ($gpioinfo && str_contains($gpioinfo, 'output')) {
            $outputPins[] = $pinNumber;
            Cache::put('pinout.output_pins', array_unique($outputPins));
            return Func::OUTPUT;
        }
        
        // Default to INPUT if we can't determine or if it's not output
        return Func::INPUT;
    }

    protected function getLevel(int $pinNumber): Level
    {
        // Check if this is an output pin - if so, return cached value
        $outputPins = Cache::get('pinout.output_pins', []);
        if (in_array($pinNumber, $outputPins, true)) {
            $cached = Cache::get("gpio.output.$pinNumber", null);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // For input pins, read the actual level
        $chip = $this->gpioChip;
        $cmd = sprintf('gpioget -c %s %d 2>/dev/null', $chip, $pinNumber);
        $result = @shell_exec($cmd);
        $output = $result ? trim($result) : '';

        if ($output === '') {
            throw new RuntimeException("Failed to read GPIO {$pinNumber}");
        }

        // Parse gpioget output format: "26"=active or "26"=inactive
        // Or just "1" or "0" or "active"/"inactive"
        if (str_contains($output, '=active') || 
            preg_match('/"*\d+"*\s*=\s*active/i', $output) ||
            trim($output) === '1' || 
            trim($output) === 'active') {
            return Level::HIGH;
        }

        return Level::LOW;
    }

    public function setFunction(int $pinNumber, Func $func): self
    {
        // With libgpiod, setting a level implicitly makes the pin output
        // So when setting to OUTPUT, we set it to LOW by default
        if ($func === Func::OUTPUT) {
            $outputPins = Cache::get('pinout.output_pins', []);
            if (!in_array($pinNumber, $outputPins, true)) {
                $outputPins[] = $pinNumber;
                Cache::put('pinout.output_pins', array_unique($outputPins));
            }
            return $this->setLevel($pinNumber, Level::LOW);
        }
        
        // For INPUT, remove from output tracking
        $outputPins = Cache::get('pinout.output_pins', []);
        $outputPins = array_values(array_filter($outputPins, fn($p) => $p !== $pinNumber));
        Cache::put('pinout.output_pins', $outputPins);
        
        // For INPUT, direction is implicit - just reading makes it input
        return $this;
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
        Cache::put("gpio.output.$pinNumber", $level);
        
        // Small delay to ensure command starts
        usleep(10000); // 10ms

        return $this;
    }
}
