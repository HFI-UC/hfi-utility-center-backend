<?php
// CustomPDOStatement.php

require_once 'global_error_handler.php'; // 确保包含异常处理器

class CustomPDOStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function __call($name, $arguments)
    {
        try {
            // 对于未被覆盖的方法，正常调用
            return call_user_func_array([$this, $name], $arguments);

        } catch (PDOException $e) {
            // 调用日志记录函数
            logException($e);
            // 重新抛出异常，确保外部调用者能处理
            throw $e;
        }
    }

    // 默认以 PDO::FETCH_ASSOC 模式获取单行
    public function fetch(?int $mode = PDO::FETCH_ASSOC, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0)
    {
        try {
            return parent::fetch($mode, $cursorOrientation, $cursorOffset);
        } catch (PDOException $e) {
            logException($e);
            throw $e;
        }
    }

    // 默认以 PDO::FETCH_ASSOC 模式获取所有行
    public function fetchAll(int $mode = PDO::FETCH_ASSOC, ...$args)
    {
        try {
            return parent::fetchAll($mode, ...$args);
        } catch (PDOException $e) {
            logException($e);
            throw $e;
        }
    }
    
    // 重写 execute 方法，添加性能优化和错误处理
    public function execute($params = null)
    {
        try {
            $startTime = microtime(true);
            $result = parent::execute($params);
            $executionTime = microtime(true) - $startTime;
            
            // 如果查询执行时间超过一定阈值，记录为慢查询
            if ($executionTime > 0.1) { // 100ms阈值，可调整
                error_log(sprintf(
                    "慢查询警告: %.4f秒 - %s", 
                    $executionTime, 
                    $this->queryString
                ));
            }
            
            return $result;
        } catch (PDOException $e) {
            logException($e);
            throw $e;
        }
    }
}

?>
