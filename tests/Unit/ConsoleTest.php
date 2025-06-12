<?php
namespace AlibabaCloud\Dara\Tests;

use PHPUnit\Framework\TestCase;
use AlibabaCloud\Dara\Util\Console;

class ConsoleTest extends TestCase
{
    private $stderrStream;
    private $stdoutStream;

    /**
     * @before
     */
    protected function initialize()
    {
        // 创建临时流来捕获输出
        $this->stderrStream = fopen('php://temp', 'w+');
        $this->stdoutStream = fopen('php://temp', 'w+');
        
        // 设置自定义流
        Console::setStderrStream($this->stderrStream);
        Console::setStdoutStream($this->stdoutStream);
    }

    /**
     * @after
     */
    protected function cleanup()
    {
        // 重置流
        Console::resetStreams();
        
        // 关闭临时流
        if (is_resource($this->stderrStream)) {
            fclose($this->stderrStream);
        }
        if (is_resource($this->stdoutStream)) {
            fclose($this->stdoutStream);
        }
    }

    public function testLog()
    {
        Console::log("Sample log message");
        $this->assertStreamContains("[LOG] Sample log message\n", $this->stdoutStream);
    }

    public function testInfo()
    {
        Console::info("Sample info message");
        $this->assertStreamContains("[INFO] Sample info message\n", $this->stdoutStream);
    }

    public function testWarning()
    {
        Console::warning("Sample warning message");
        $this->assertStreamContains("[WARNING] Sample warning message\n", $this->stdoutStream);
    }

    public function testDebug()
    {
        Console::debug("Sample debug message");
        $this->assertStreamContains("[DEBUG] Sample debug message\n", $this->stdoutStream);
    }

    public function testError()
    {
        Console::error("Sample error message");
        $this->assertStreamContains("[ERROR] Sample error message\n", $this->stderrStream);
    }

    /**
     * 辅助方法：检查流中是否包含期望的内容
     */
    private function assertStreamContains($expected, $stream)
    {
        rewind($stream);
        $actual = stream_get_contents($stream);
        $this->assertEquals($expected, $actual);
    }
}
