<?php

namespace DeptOfScrapyardRobotics\Displays\SDL3;

use BareMetal\Contracts\Circuits\BootScaffolding;
use DeptOfScrapyardRobotics\Displays\SDL3\Enums\SDL3OpCode;

trait SDL3DisplayInternalAPI
{
    use BootScaffolding;

    /**
     * @throws SDL3DisplayException
     */
    protected function command(SDL3OpCode $register, array $command_data = []): int
    {
        return $this->transport->command($register->value, $command_data);
    }

    /**
     * @throws SDL3DisplayException
     */
    protected function data(array $data = []): void
    {
        $this->transport->data($data);
    }

    /**
     * @throws SDL3DisplayException
     */
    protected function _boot(): void
    {
        $this->transport->open($this->width, $this->height, $this->_scale_factor);
    }
}
