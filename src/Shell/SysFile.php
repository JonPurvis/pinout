<?php

namespace DanJohnson95\Pinout\Shell;

use DanJohnson95\Pinout\Collections\PinCollection;
use DanJohnson95\Pinout\Entities\Pin;
use DanJohnson95\Pinout\Enums\Func;
use DanJohnson95\Pinout\Enums\Level;
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
        // Track pins we've set as output since gpioinfo may not work reliably
        if (in_array($pinNumber, $this->outputPins, true)) {
            return Func::OUTPUT;
        }
        
        // Try to detect using gpioinfo if available
        $chip = $this->gpioChip;
        $cmd = "bash -c 'gpioinfo $chip 2>/dev/null | grep -E \"^\\\\s*line\\\\s+$pinNumber:\"'";
        $gpioinfo = @shell_exec($cmd);
        
        if ($gpioinfo && str_contains($gpioinfo, 'output')) {
            $this->outputPins[] = $pinNumber;
            return Func::OUTPUT;
        }
        
        // Default to INPUT if we can't determine or if it's not output
        return Func::INPUT;
    }

    protected function getLevel(int $pinNumber): Level
    {
        $chip = $this->gpioChip;
        $cmd = sprintf('gpioget -c %s %d 2>/dev/null', $chip, $pinNumber);
        $output = trim(shell_exec($cmd));

        if ($output === '') {
            throw new RuntimeException("Failed to read GPIO {$pinNumber}");
        }

        if (str_contains($output, 'active')) {
            return Level::HIGH;
        }

        return Level::LOW;
    }

    public function setFunction(int $pinNumber, Func $func): self
    {
        // With libgpiod, setting a level implicitly makes the pin output
        // So when setting to OUTPUT, we set it to LOW by default
        if ($func === Func::OUTPUT) {
            if (!in_array($pinNumber, $this->outputPins, true)) {
                $this->outputPins[] = $pinNumber;
            }
            return $this->setLevel($pinNumber, Level::LOW);
        }
        
        // For INPUT, remove from output tracking
        $this->outputPins = array_values(array_filter($this->outputPins, fn($p) => $p !== $pinNumber));
        
        // For INPUT, direction is implicit - just reading makes it input
        return $this;
    }

    public function setLevel(int $pinNumber, Level $level): self
    {
        $chip = $this->gpioChip;
        $value = $level === Level::HIGH ? 1 : 0;

        $cmd = sprintf(
            'gpioset %s %d=%d 2>/dev/null',
            $chip,
            $pinNumber,
            $value
        );

        shell_exec($cmd);

        return $this;
    }
}
