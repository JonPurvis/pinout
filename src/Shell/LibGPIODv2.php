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
        
        // Ignore child process signals so PHP doesn't wait for background gpioset processes
        // This allows Tinker to exit cleanly
        if (function_exists('pcntl_signal')) {
            @pcntl_signal(SIGCHLD, SIG_IGN);
        }
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

        // If no level specified and pin is already OUTPUT, preserve current level
        if ($level === null) {
            try {
                $currentLevel = $this->getLevel($pinNumber);
                $level = $currentLevel;
            } catch (\Exception $e) {
                // If we can't get current level, default to LOW
                $level = Level::LOW;
            }
        }

        return $this->setLevel(
            $pinNumber,
            $level
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

        // Kill existing gpioset process for this pin (synchronous but fast)
        // First try by PID file if it exists
        if (file_exists($pidFile)) {
            $oldPid = trim(@file_get_contents($pidFile));
            if (is_numeric($oldPid) && posix_kill((int)$oldPid, 0)) {
                posix_kill((int)$oldPid, SIGTERM);
                usleep(10000); // 10ms - brief wait for graceful termination
                if (posix_kill((int)$oldPid, 0)) {
                    posix_kill((int)$oldPid, SIGKILL);
                    usleep(5000); // 5ms
                }
            }
            @unlink($pidFile);
        }
        
        // Also kill by pattern to catch any stragglers (synchronous)
        shell_exec("pkill -f 'gpioset.*-c {$this->gpioChip}.*$pinNumber=' 2>/dev/null");
        usleep(10000); // 10ms

        // Start new gpioset process - double background to fully detach from PHP
        // The outer & backgrounds the setsid command, ensuring PHP doesn't wait
        $pidCmd = sprintf(
            '(setsid bash -c "echo \\$\\$ > %s; exec gpioset -c %s %d=%d </dev/null >/dev/null 2>&1" &) &',
            $pidFile,
            $this->gpioChip,
            $pinNumber,
            $value
        );
        
        // Execute with all output redirected - returns immediately
        exec($pidCmd . ' >/dev/null 2>&1');
        
        // Don't wait for PID file - let it happen asynchronously
        // This prevents blocking Tinker exit. The process will start in background.
        // We'll check for PID file on next access if needed.

        // Update cache immediately
        $this->cache($pinNumber, $level);
        return $this;
    }

    /**
     * Set multiple pin levels in a single gpioset command for better performance
     * 
     * @param array<int, Level> $pinLevels Array of pinNumber => Level
     * @return self
     */
    public function setLevels(array $pinLevels): self
    {
        if (empty($pinLevels)) {
            return $this;
        }

        $pidFiles = [];
        $pinNumbers = array_keys($pinLevels);

        // Kill existing gpioset processes for all pins
        foreach ($pinNumbers as $pinNumber) {
            $pidFile = "/tmp/pinout_gpioset_$pinNumber.pid";
            $pidFiles[] = $pidFile;
            
            if (file_exists($pidFile)) {
                $oldPid = trim(@file_get_contents($pidFile));
                if (is_numeric($oldPid) && posix_kill((int)$oldPid, 0)) {
                    posix_kill((int)$oldPid, SIGTERM);
                    usleep(5000); // 5ms
                    if (posix_kill((int)$oldPid, 0)) {
                        posix_kill((int)$oldPid, SIGKILL);
                    }
                }
                @unlink($pidFile);
            }
        }
        
        // Kill any batch processes that might include these pins
        // Check all possible batch PID files and kill any that contain our pins
        $globPattern = "/tmp/pinout_gpioset_batch_*.pid";
        $batchFiles = glob($globPattern);
        if ($batchFiles) {
            foreach ($batchFiles as $batchFile) {
                // Extract pin numbers from filename
                $filename = basename($batchFile, '.pid');
                $filePins = explode('_', str_replace('pinout_gpioset_batch_', '', $filename));
                $filePins = array_map('intval', $filePins);
                
                // If any pin matches, kill this batch process
                if (array_intersect($pinNumbers, $filePins)) {
                    $oldPid = trim(@file_get_contents($batchFile));
                    if (is_numeric($oldPid)) {
                        if (posix_kill((int)$oldPid, 0)) {
                            posix_kill((int)$oldPid, SIGTERM);
                            usleep(10000); // 10ms
                            if (posix_kill((int)$oldPid, 0)) {
                                posix_kill((int)$oldPid, SIGKILL);
                                usleep(5000); // 5ms
                            }
                        }
                    }
                    @unlink($batchFile);
                }
            }
        }
        
        // Kill individual gpioset processes for these pins
        foreach ($pinNumbers as $pinNumber) {
            shell_exec("pkill -9 -f 'gpioset.*-c {$this->gpioChip}.*$pinNumber=' 2>/dev/null");
        }
        
        // Brief delay to ensure processes are killed before starting new batch
        usleep(50000); // 50ms

        // Build single gpioset command with all pins: gpioset -c chip 20=1 21=0 22=1
        $pinArgs = [];
        foreach ($pinLevels as $pinNumber => $level) {
            $pinArgs[] = "$pinNumber=" . $level->value;
            // Update cache immediately
            $this->cache($pinNumber, $level);
        }
        
        $pinArgsStr = implode(' ', $pinArgs);
        
        // Create a single PID file for the batch process (sort pins for consistency)
        $sortedPins = $pinNumbers;
        sort($sortedPins);
        $batchPidFile = "/tmp/pinout_gpioset_batch_" . implode('_', $sortedPins) . ".pid";
        
        // Start single gpioset process for all pins - double background to fully detach from PHP
        $pidCmd = sprintf(
            '(setsid bash -c "echo \\$\\$ > %s; exec gpioset -c %s %s </dev/null >/dev/null 2>&1" &) &',
            escapeshellarg($batchPidFile),
            escapeshellarg($this->gpioChip),
            $pinArgsStr // Already space-separated arguments: "12=1 13=0 16=1"
        );
        
        // Execute with all output redirected - returns immediately
        exec($pidCmd . ' >/dev/null 2>&1');
        
        // Don't wait for PID file - let it happen asynchronously
        // This prevents blocking Tinker exit. The process will start in background.
        // PID files will be created asynchronously by the background process.

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

