<?php
/*
 *  Copyright 2026.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Wildberries\Support\Messenger\WbReview;

use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Symfony\Component\Uid\Uuid;

final class WildberriesReviewMessage
{
    /**
     * Идентификатор товара
     */
    private string $article;

    /**
     * Идентификатор профиля магазина
     */
    private string $profile;


    /**
     * Имя пользователя в отзыве
     */
    private string $author;


    /**
     * Оценка в отзыве
     */
    private int $rating;

    /**
     * Текст/комментарий отзыва
     */
    private string $text;

    /**
     * Идентификатор токена
     */
    private string $token;


    /**
     * Внешний идентификатор
     */
    private string $external;


    public function __construct(
        string $article,
        int $rating,
        string $text,
        Uuid $token,
        string $external,
        UserProfileUid|string $profile,
        string $author,
    )
    {
        $this->article = $article;
        $this->rating = $rating;
        $this->text = $text;
        $this->token = (string) $token;
        $this->external = $external;
        $this->profile = (string) $profile;
        $this->author = $author;
    }


    public function getArticle(): string
    {
        return $this->article;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getToken(): Uuid
    {
        return new Uuid($this->token);
    }

    public function getExternal(): string
    {
        return $this->external;
    }

    public function getProfile(): UserProfileUid
    {
        return new UserProfileUid($this->profile);
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

}