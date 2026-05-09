<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum UserRole: string
{
    case MasterAdmin = 'master_admin';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Agent = 'agent'; // generic; specific roles below for Prime-style ops
    case Fronter = 'fronter';
    case Closer = 'closer';
    case Manager = 'manager';
    case QA = 'qa';

    public function isAdmin(): bool
    {
        return in_array($this, [self::MasterAdmin, self::Admin], true);
    }

    public function canTakeCalls(): bool
    {
        return in_array($this, [self::Agent, self::Fronter, self::Closer], true);
    }

    public function canSupervise(): bool
    {
        return in_array($this, [
            self::MasterAdmin, self::Admin, self::Supervisor, self::Manager,
        ], true);
    }
}
