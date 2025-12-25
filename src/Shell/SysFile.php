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
        // libgpiod does not expose direction cleanly via CLI
        // Best-effort assumption: input unless explicitly driven
        return Func::INPUT;
    }

    protected function getLevel(int $pinNumber): Level
    {
        $cmd = sprintf('gpioget -c gpiochip0 %d 2>/dev/null', $pinNumber);
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
        // Direction is implicit in libgpiod
        // We accept the call for API compatibility
        return $this;
    }

    public function setLevel(int $pinNumber, Level $level): self
    {
        $value = $level === Level::HIGH ? 1 : 0;

        $cmd = sprintf(
            'gpioset gpiochip0 %d=%d 2>/dev/null',
            $pinNumber,
            $value
        );

        shell_exec($cmd);

        return $this;
    }
}
