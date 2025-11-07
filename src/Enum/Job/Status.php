<?php

namespace Nails\Queue\Enum\Job;

enum Status: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETE = 'COMPLETE';
    case FAILED = 'FAILED';
}
