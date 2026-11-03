<?php

namespace Hypermed\Messaging\Support;

use Illuminate\Database\Eloquent\Model;

/** Small typed accessors around config('messaging.*'). */
final class MessagingConfig
{
    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return config('messaging.user_model', 'App\\Models\\User');
    }

    public static function userInstance(): Model
    {
        $class = self::userModel();

        return new $class;
    }

    public static function userTable(): string
    {
        return self::userInstance()->getTable();
    }

    public static function userKey(): string
    {
        return self::userInstance()->getKeyName();
    }

    public static function nameColumn(): string
    {
        return (string) config('messaging.user_name_column', 'name');
    }

    public static function avatarColumn(): ?string
    {
        $col = config('messaging.user_avatar_column');

        return $col ? (string) $col : null;
    }

    public static function previewLength(): int
    {
        return (int) config('messaging.messages.preview_length', 120);
    }
}
