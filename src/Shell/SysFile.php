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
        $chip = $this->gpioChip;
        // Suppress errors and check if line is configured as output
        $cmd = "gpioinfo $chip 2>&1 | grep -E '^\\s*line\\s+$pinNumber:' || true";
        $gpioinfo = @shell_exec($cmd);
        
        if ($gpioinfo && str_contains($gpioinfo, 'output')) {
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
            return $this->setLevel($pinNumber, Level::LOW);
        }
        
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
