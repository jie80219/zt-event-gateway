<?php

namespace SDPMlab\ZtEventGateway;

use ReflectionClass;
use ReflectionMethod;
use SDPMlab\ZtEventGateway\EventBus;

/**
 * Class HandlerScanner
 * 用於自動掃描 `Saga` 事件處理類別，並自動註冊標記為 `#[EventHandler]` 的方法。
 * 這樣，當 `Saga` 需要處理某個事件時，我們不需要手動，而是讓程式自動完成這些步驟。
 */
class HandlerScanner
{
    /**
     * 儲存已經註冊過的事件處理器，避免重複註冊
     *
     * @var array<string, array<string, bool>> 
     * 格式：
     * [
     *    'App\Events\OrderCreatedEvent' => ['onOrderCreated' => true],
     *    'App\Events\InventoryDeductedEvent' => ['onInventoryDeducted' => true],
     * ]
     */
    private array $registeredEventHandlers = [];

    /**
     * 掃描 `Saga` 內的所有類別，尋找 `#[EventHandler]` 註解的方法，並註冊到 `EventBus`
     *
     * @param string $namespace 目標掃描的命名空間 (通常是 `App\Sagas`)
     * @param EventBus $eventBus 事件匯流排，用於註冊 `EventHandler`
     * 
     * @return void
     */
    public function scanAndRegisterHandlers(string $namespace, EventBus $eventBus)
    {
        // 取得 `namespace` 下的所有類別
        $classes = $this->getClassesInNamespace($namespace);

        foreach ($classes as $class) {
            #echo "Scanning class: $class\n"; // ✅ Debug 訊息，確認找到的 Saga 類別

            // 確保類別存在，避免因為 autoload 失敗而報錯
            if (!class_exists($class)) {
                echo " [⚠] Class does not exist: $class\n";
                continue;
            }

            // ✅ **初始化該 Saga，並傳入 `EventBus`
            $instance = new $class($eventBus);

            $reflectionClass = new ReflectionClass($class);

            // 掃描該類別內的所有 `public` 方法
            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // 檢查該方法是否有使用 `#[EventHandler]` 註解
                foreach ($method->getAttributes() as $attribute) {
                   
                        //echo "✅ Registering Saga EventHandler: $class::{$method->getName()}\n";

                        // 透過 `Reflection` 取得該方法處理的事件類型
                        $eventType = $this->getEventTypeFromMethod($class, $method->getName());

                        // ✅ 確保不重複註冊相同的 `EventHandler`
                        if ($eventType && !isset($this->registeredEventHandlers[$eventType][$method->getName()])) {
                            //echo " [✔] Registered EventHandler for event: $eventType -> $class::{$method->getName()}\n";

                            // 註冊到 `EventBus`
                            $eventBus->registerHandler($eventType, [$instance, $method->getName()]);

                            // 標記此 `EventHandler` 已經註冊，避免重複註冊
                            $this->registeredEventHandlers[$eventType][$method->getName()] = true;
                        }
                    
                }
            }
        }
    }

    /**
     * 根據指定 `namespace` 取得所有類別
     * 
     * @param string $namespace - 目標命名空間 (如 `App\Sagas`)
     * @return array<string> - 找到的類別名稱清單
     */
    private function getClassesInNamespace(string $namespace): array
    {
        // 讓 "App" 命名空間對應到專案的根目錄
        $baseDir = realpath(__DIR__ . '/../');
        $relativePath = str_replace('\\', '/', str_replace('App\\', '', $namespace));
        $directory = $baseDir . '/' . $relativePath;

        //echo "Scanning directory: $directory\n"; // ✅ Debug 訊息，確認目錄位置

        // 確保目錄存在，否則返回空陣列
        if (!is_dir($directory)) {
            echo " [⚠] Directory does not exist: $directory\n";
            return [];
        }

        // 取得該目錄下的所有 `.php` 檔案
        $files = glob($directory . '/*.php');

        // 若該目錄沒有任何 PHP 檔案，則輸出錯誤
        if (!$files) {
            echo " [⚠] No files found in: $directory\n"; 
        }

        $classes = [];
        foreach ($files as $file) {
            $className = $namespace . '\\' . basename($file, '.php');
            #echo "Found class file: $className\n"; // ✅ 確認找到的 Saga 類別
            $classes[] = $className;
        }
        
        return $classes;
    }

    /**
     * 從方法的參數推斷其對應的事件類型
     *
     * @param string $class - 目標類別名稱
     * @param string $method - 方法名稱
     * @return string|null - 返回事件類型的完整名稱 (如 `App\Events\OrderCreatedEvent`)，如果找不到則返回 `null`
     */
    private function getEventTypeFromMethod($class, $method)
    {
        $reflectionMethod = new ReflectionMethod($class, $method);
        $parameters = $reflectionMethod->getParameters();
        
        // 如果該方法只有一個參數，則該參數的類型即為事件類型
        if (count($parameters) === 1) {
            return $parameters[0]->getType()->getName();
        }
        
        return null;
    }

    /**
     * 掃描指定的 Saga 文件，提取所有 EventHandler 方法中的 Event 類型名稱
     *
     * @param string $sagaFilePath - Saga 文件的路徑
     * @return array<string> - Event 類型名稱陣列 (只包含類名，不含命名空間)
     * @throws \Exception - 當文件不存在時拋出異常
     */
    public function scanEventTypesFromFile(string $sagaFilePath): array
    {
        if (!file_exists($sagaFilePath)) {
            throw new \Exception("Saga file not found: $sagaFilePath");
        }
        
        $content = file_get_contents($sagaFilePath);
        $eventTypes = [];
        
        // 使用正則表達式找到所有 #[EventHandler] 標籤後的方法
        // 匹配模式：#[EventHandler] 後面跟著 public function 和參數中的 Event 類型
        $pattern = '/#\[EventHandler\]\s*\n\s*public\s+function\s+\w+\s*\(\s*(\w+)\s+\$\w+/';
        
        if (preg_match_all($pattern, $content, $matches)) {
            $eventTypes = array_unique($matches[1]); // 獲取第一個捕獲組（Event 類型名稱）並去重
        }
        
        return array_values($eventTypes);
    }

    /**
     * 掃描多個 Saga 文件，提取所有 EventHandler 方法中的 Event 類型名稱
     *
     * @param array<string> $sagaFilePaths - Saga 文件路徑陣列
     * @return array<string> - 合併後的 Event 類型名稱陣列
     */
    public function scanEventTypesFromFiles(array $sagaFilePaths): array
    {
        $allEventTypes = [];
        
        foreach ($sagaFilePaths as $filePath) {
            try {
                $eventTypes = $this->scanEventTypesFromFile($filePath);
                $allEventTypes = array_merge($allEventTypes, $eventTypes);
            } catch (\Exception $e) {
                echo "警告：無法掃描文件 $filePath - " . $e->getMessage() . "\n";
            }
        }
        
        return array_values(array_unique($allEventTypes));
    }

    /**
     * 掃描指定目錄下的所有 Saga 文件，提取 Event 類型名稱
     *
     * @param string $sagaDirectory - Saga 目錄路徑
     * @return array<string> - Event 類型名稱陣列
     */
    public function scanEventTypesFromDirectory(string $sagaDirectory): array
    {
        if (!is_dir($sagaDirectory)) {
            throw new \Exception("Directory not found: $sagaDirectory");
        }
        
        $sagaFiles = glob($sagaDirectory . '/*.php');
        
        if (!$sagaFiles) {
            return [];
        }
        
        return $this->scanEventTypesFromFiles($sagaFiles);
    }

    /**
     * 掃描 Saga 文件並設定 RabbitMQ Queue
     *
     * @param string $sagaFilePath - Saga 文件路徑
     * @param object $rabbitMQ - RabbitMQ 連接對象
     * @param string $exchangeName - Exchange 名稱
     * @return array<string> - 成功設定的 Queue 名稱陣列
     */
    public function scanAndSetupQueues(string $sagaFilePath, $rabbitMQ, string $exchangeName): array
    {
        // 掃描 Event 類型
        $queueNames = $this->scanEventTypesFromFile($sagaFilePath);
        
        // 設定 RabbitMQ Queue
        foreach ($queueNames as $queueName) {
            $rabbitMQ->setupQueue($queueName, $exchangeName, $queueName);
        }
        
        return $queueNames;
    }

    /**
     * 顯示掃描和設定結果
     *
     * @param array<string> $queueNames - Queue 名稱陣列
     * @return void
     */
    public function displaySetupResults(array $queueNames): void
    {
        echo "Scan successful! Found " . count($queueNames) . " Event types\n";
        echo "RabbitMQ Queue list:\n";
        foreach ($queueNames as $queueName) {
            echo "   - " . $queueName . "\n";
        }
        echo "Setup completed!\n";
    }

    /**
     * 檢測當前作業系統
     *
     * @return string - 'windows', 'linux', 'mac' 或 'unknown'
     */
    private function detectOS(): string
    {
        $os = strtolower(PHP_OS);
        
        if (str_contains($os, 'win')) {
            return 'windows';
        } elseif (str_contains($os, 'linux')) {
            return 'linux';
        } elseif (str_contains($os, 'darwin')) {
            return 'mac';
        }
        
        return 'unknown';
    }

    /**
     * 根據掃描到的事件類型生成 Windows batch 文件
     *
     * @param array<string> $eventTypes - 事件類型陣列
     * @param string $outputPath - 輸出的 bat 文件路徑
     * @return void
     */
    private function generateWindowsBatchFile(array $eventTypes, string $outputPath): void
    {
        $batContent = "@echo off\n";
        $batContent .= "cd /d %~dp0\\..\n\n";
        $batContent .= "echo Starting all event listeners...\n\n";
        
        foreach ($eventTypes as $eventType) {
            $batContent .= "echo Starting {$eventType} listener...\n";
            $batContent .= "start cmd /k \"php consumer.php {$eventType}\"\n";
        }
        
        $batContent .= "\necho All listeners started\n";
        $batContent .= "pause\n";
        
        file_put_contents($outputPath, $batContent);
    }

    /**
     * 根據掃描到的事件類型生成 Linux/Mac shell 腳本
     *
     * @param array<string> $eventTypes - 事件類型陣列
     * @param string $outputPath - 輸出的 shell 腳本路徑
     * @return void
     */
    private function generateUnixShellScript(array $eventTypes, string $outputPath): void
    {
        $shellContent = "#!/bin/bash\n\n";
        $shellContent .= "# Switch to project root directory\n";
        $shellContent .= "cd \"$(dirname \"\$0\")/..\"\n\n";
        $shellContent .= "# Create necessary directories\n";
        $shellContent .= "mkdir -p tmp/pids tmp/logs\n\n";
        $shellContent .= "echo \"Starting all event listeners...\"\n\n";
        
        // 生成啟動腳本
        foreach ($eventTypes as $eventType) {
            $shellContent .= "echo \"Starting {$eventType} listener...\"\n";
            $shellContent .= "nohup php consumer.php {$eventType} > tmp/logs/{$eventType}.log 2>&1 &\n";
            $shellContent .= "echo \$! > tmp/pids/{$eventType}.pid\n\n";
        }
        
        $shellContent .= "echo \"All listeners started\"\n";
        $shellContent .= "echo \"Check status: ./scripts/check_status.sh\"\n";
        $shellContent .= "echo \"Stop all listeners: ./scripts/stop_all.sh\"\n";
        $shellContent .= "echo \"View logs: tail -f tmp/logs/*.log\"\n";
        
        file_put_contents($outputPath, $shellContent);
        
        // 生成狀態檢查腳本
        $this->generateStatusScript(dirname($outputPath) . '/check_status.sh', $eventTypes);
        
        // 生成停止腳本
        $this->generateStopScript(dirname($outputPath) . '/stop_all.sh', $eventTypes);
        
        // 為 shell 腳本添加執行權限
        chmod($outputPath, 0755);
    }

    /**
     * 生成狀態檢查腳本
     *
     * @param string $outputPath - 輸出路徑
     * @param array<string> $eventTypes - 事件類型陣列
     * @return void
     */
    private function generateStatusScript(string $outputPath, array $eventTypes): void
    {
        $content = "#!/bin/bash\n\n";
        $content .= "# Switch to project root directory\n";
        $content .= "cd \"$(dirname \"\$0\")/..\"\n\n";
        $content .= "echo \"Event Listener Status:\"\n";
        $content .= "echo \"=====================\"\n\n";
        
        foreach ($eventTypes as $eventType) {
            $content .= "if [ -f tmp/pids/{$eventType}.pid ]; then\n";
            $content .= "    PID=\$(cat tmp/pids/{$eventType}.pid)\n";
            $content .= "    if ps -p \$PID > /dev/null 2>&1; then\n";
            $content .= "        echo \"[RUNNING] {$eventType}: Running (PID: \$PID)\"\n";
            $content .= "    else\n";
            $content .= "        echo \"[STOPPED] {$eventType}: Stopped\"\n";
            $content .= "        rm -f tmp/pids/{$eventType}.pid\n";
            $content .= "    fi\n";
            $content .= "else\n";
            $content .= "    echo \"[NOT_STARTED] {$eventType}: Not started\"\n";
            $content .= "fi\n\n";
        }
        
        file_put_contents($outputPath, $content);
        chmod($outputPath, 0755);
    }

    /**
     * 生成停止腳本
     *
     * @param string $outputPath - 輸出路徑
     * @param array<string> $eventTypes - 事件類型陣列
     * @return void
     */
    private function generateStopScript(string $outputPath, array $eventTypes): void
    {
        $content = "#!/bin/bash\n\n";
        $content .= "# Switch to project root directory\n";
        $content .= "cd \"$(dirname \"\$0\")/..\"\n\n";
        $content .= "echo \"Stopping all event listeners...\"\n\n";
        
        foreach ($eventTypes as $eventType) {
            $content .= "if [ -f tmp/pids/{$eventType}.pid ]; then\n";
            $content .= "    PID=\$(cat tmp/pids/{$eventType}.pid)\n";
            $content .= "    if ps -p \$PID > /dev/null 2>&1; then\n";
            $content .= "        kill \$PID\n";
            $content .= "        echo \"Stopped {$eventType} (PID: \$PID)\"\n";
            $content .= "    fi\n";
            $content .= "    rm -f tmp/pids/{$eventType}.pid\n";
            $content .= "fi\n\n";
        }
        
        $content .= "echo \"All listeners stopped\"\n";
        
        file_put_contents($outputPath, $content);
        chmod($outputPath, 0755);
    }

    /**
     * 根據掃描到的事件類型生成跨平台腳本文件
     *
     * @param array<string> $eventTypes - 事件類型陣列
     * @param string $outputDir - 輸出目錄路徑
     * @return array<string> - 生成的文件路徑陣列
     */
    public function generateCrossPlatformScripts(array $eventTypes, string $outputDir = ''): array
    {
        if (empty($outputDir)) {
            $outputDir = './scripts';
        }
        
        // 確保 scripts 目錄存在
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $generatedFiles = [];
        $currentOS = $this->detectOS();
        
        // 生成 Windows batch 文件
        $batPath = $outputDir . '/run_all_events.bat';
        $this->generateWindowsBatchFile($eventTypes, $batPath);
        $generatedFiles[] = $batPath;
        
        // 生成 Linux/Mac shell 腳本
        $shellPath = $outputDir . '/run_all_events.sh';
        $this->generateUnixShellScript($eventTypes, $shellPath);
        $generatedFiles[] = $shellPath;
        
        return [
            'current_os' => $currentOS,
            'files' => $generatedFiles,
            'recommended' => $currentOS === 'windows' ? $batPath : $shellPath
        ];
    }

    /**
     * 根據掃描到的事件類型生成當前系統適用的腳本文件
     *
     * @param array<string> $eventTypes - 事件類型陣列
     * @param string $outputPath - 輸出文件路徑 (可選)
     * @return void
     */
    public function generateBatchFile(array $eventTypes, string $outputPath = ''): void
    {
        $currentOS = $this->detectOS();
        
        if (empty($outputPath)) {
            // 確保 scripts 目錄存在
            if (!is_dir('./scripts')) {
                mkdir('./scripts', 0755, true);
            }
            $outputPath = $currentOS === 'windows' ? './scripts/run_all_events.bat' : './scripts/run_all_events.sh';
        }
        
        if ($currentOS === 'windows') {
            $this->generateWindowsBatchFile($eventTypes, $outputPath);
        } else {
            $this->generateUnixShellScript($eventTypes, $outputPath);
        }
    }

    /**
     * 掃描 Saga 文件並生成完整的 bat 文件
     *
     * @param string $sagaFilePath - Saga 文件路徑
     * @param string $batFilePath - 要生成的 bat 文件路徑 (預設為 run_all_events.bat)
     * @return array<string> - 掃描到的事件類型
     */
    public function scanAndGenerateBatchFile(string $sagaFilePath, string $batFilePath = 'run_all_events.bat'): array
    {
        // 掃描事件類型
        $eventTypes = $this->scanEventTypesFromFile($sagaFilePath);
        
        // 生成 bat 文件
        $this->generateBatchFile($eventTypes, $batFilePath);
        
        return $eventTypes;
    }

    /**
     * 顯示腳本文件生成結果
     *
     * @param array<string> $eventTypes - 事件類型陣列
     * @param string|array $filePath - 生成的文件路徑或路徑陣列
     * @return void
     */
    public function displayBatchFileResults(array $eventTypes, $filePath): void
    {
        $currentOS = $this->detectOS();
        
        echo "Detected OS: " . ucfirst($currentOS) . "\n";
        echo "Scan successful! Found " . count($eventTypes) . " event types\n";
        echo "Event type list:\n";
        foreach ($eventTypes as $eventType) {
            echo "   - " . $eventType . "\n";
        }
        
        if (is_array($filePath)) {
            echo "Generated cross-platform script files:\n";
            foreach ($filePath['files'] as $file) {
                $isRecommended = ($file === $filePath['recommended']) ? " (recommended)" : "";
                echo "   - " . basename($file) . $isRecommended . "\n";
            }
        } else {
            echo "Generated script file: " . basename($filePath) . "\n";
        }
    }

    /**
     * 掃描 Saga 文件並生成跨平台腳本文件
     *
     * @param string $sagaFilePath - Saga 文件路徑
     * @param string $outputDir - 輸出目錄路徑 (預設為當前目錄)
     * @return array - 生成結果陣列
     */
    public function scanAndGenerateCrossPlatformScripts(string $sagaFilePath, string $outputDir = ''): array
    {
        // 掃描事件類型
        $eventTypes = $this->scanEventTypesFromFile($sagaFilePath);
        
        // 如果沒有指定輸出目錄，默認使用 scripts 目錄
        if (empty($outputDir)) {
            $outputDir = './scripts';
        }
        
        // 生成跨平台腳本文件
        $result = $this->generateCrossPlatformScripts($eventTypes, $outputDir);
        
        return array_merge($result, ['event_types' => $eventTypes]);
    }
}
