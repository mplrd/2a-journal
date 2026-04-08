<?php

namespace App\Enums;

enum CustomFieldType: string
{
    case BOOLEAN = 'BOOLEAN';
    case TEXT = 'TEXT';
    case NUMBER = 'NUMBER';
    case SELECT = 'SELECT';
}
