<?php

declare(strict_types=1);

namespace App\Images\Ui\Cli\Parallel;

final class ParallelProtocol
{
    public const string ACTION_KEY = 'action';

    public const string IDENTIFIER_KEY = 'identifier';

    public const string FILES_KEY = 'files';

    public const string RESULT_KEY = 'result';

    public const string HELLO_ACTION = 'hello';

    public const string MAIN_ACTION = 'main';

    public const string RESULT_ACTION = 'result';

    private function __construct()
    {
    }
}
