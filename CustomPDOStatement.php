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
            if ($name === 'fetch' || $name === 'fetchAll') {
                // 捕获异常并调用 customExceptionHandler
                return call_user_func_array([$this, $name], [PDO::FETCH_ASSOC]);
            }

            // 对于其他方法，正常调用
            return call_user_func_array([$this, $name], $arguments);

        } catch (PDOException $e) {
            // 调用自定义异常处理函数
            customExceptionHandler($e);
            // 你可以选择重新抛出异常，或者返回 null 或默认值
            throw $e;  // 如果希望继续抛出异常，使用 throw
        }
    }
}

?>