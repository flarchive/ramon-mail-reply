<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Companion 1:1 ao `posts`. Substitui a coluna `posts.via_mail_reply` —
 * extensão jamais ALTERa tabela do core. O backfill e o drop da coluna
 * acontecem na migration seguinte.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('mail_reply_posts')) {
            return;
        }

        $schema->create('mail_reply_posts', function (Blueprint $table) {
            $table->unsignedInteger('post_id')->primary();
            $table->foreign('post_id')
                ->references('id')->on('posts')
                ->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('mail_reply_posts');
    },
];
