<?php

namespace Tests;

use Database\Factories\ScreeningFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seanse z fabryki startują od "jutro" w każdym teście, niezależnie od kolejności testów.
        ScreeningFactory::resetSlots();
    }
}
