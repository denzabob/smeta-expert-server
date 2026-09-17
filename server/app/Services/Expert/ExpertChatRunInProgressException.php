<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertChatRunInProgressException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('В этом чате уже формируется ответ AI.');
    }
}
