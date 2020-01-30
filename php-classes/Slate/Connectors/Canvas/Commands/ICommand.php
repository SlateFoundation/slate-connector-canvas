<?php

namespace Slate\Connectors\Canvas\Commands;

interface ICommand
{
    public function describe();

    public function execute();
}
