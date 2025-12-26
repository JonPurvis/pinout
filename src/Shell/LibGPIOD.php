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

    protected bool $useChipFlag;

    public function __construct()
    {
        $this->gpioChip = config('pinout.gpio_chip');
        $this->useChipFlag = $this->requiresChipFlag();
    }

    /**
     * Determine if libgpiod v2.x is installed and requires the -c flag
     */
    protected function requiresChipFlag(): bool
    {
        $versionOutput = shell_exec("gpioset --version 2>&1");
        return str_contains($versionOutput, 'v2');
    }

    /**
     * Returns the correct chip argument for CLI commands
     */
    protected function chipArg(): string
    {
        return $this->useChipFlag ? "-c {$this->gpioChip}" : $this->gpioChip;
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
        // Check if direction is output
        $gpioinfo = shell_exec("gpioinfo {$this->chipArg()} | grep -E '^\\s*line\\s+$pinNumber:'");

        if (str_contains($gpioinfo, 'output')) {
            return $this->cache($pinNumber);
        }

        // Input — safe to read
        $level = trim(shell_exec("gpioget {$this->chipArg()} $pinNumber"));

        return match ($level) {
            "0" => Level::LOW,
            "1" => Level::HIGH,
            default => throw new \Exception("Unknown GPIO level '$level' on line $pinNumber"),
        };
    }

    public function getFunction(int $pinNumber): Func
    {
        $chip = $this->gpioChip;

        $gpioinfo = shell_exec("gpioinfo {$this->chipArg()} | grep -E '^\\s*line\\s+$pinNumber:'");

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
            shell_exec("gpioget {$this->chipArg()} $pinNumber");
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
            if (is_numeric($oldPid) && posix_kill((int)$oldPid, 0)) {
                posix_kill((int)$oldPid, SIGTERM);
            }
            @unlink($pidFile);
        }

        // v2: Run in background to hold pin; v1: Exits immediately
        $cmd = sprintf(
            'setsid gpioset %s %d=%d >/dev/null 2>&1 & echo $!',
            $this->chipArg(),
            $pinNumber,
            $value
        );

        $pid = trim(shell_exec($cmd));
        if ($pid && $this->useChipFlag) {
            file_put_contents($pidFile, $pid);
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