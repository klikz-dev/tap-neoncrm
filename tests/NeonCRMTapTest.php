<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;

class NeonCRMTapTest extends TestCase
{
    public function testHasDesiredMethods()
    {
        $this->assertTrue(method_exists('NeonCRMTap', 'test'));
        $this->assertTrue(method_exists('NeonCRMTap', 'discover'));
        $this->assertTrue(method_exists('NeonCRMTap', 'tap'));
        $this->assertTrue(method_exists('NeonCRMTap', 'getTables'));
    }
}
