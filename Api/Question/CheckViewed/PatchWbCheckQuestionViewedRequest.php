<?php
/*
 * Copyright 2025.  Baks.dev <admin@baks.dev>
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

namespace BaksDev\Wildberries\Support\Api\Question\CheckViewed;

use BaksDev\Wildberries\Api\Wildberries;

final class PatchWbCheckQuestionViewedRequest extends Wildberries
{
    /** Идентификатор вопроса */
    private string|false $id = false;

    public function id(string $chat): self
    {
        $this->id = $chat;

        return $this;
    }

    /**
     * В зависимости от тела запроса, метод позволяет:
     *
     * отметить вопрос как просмотренный
     * отклонить вопрос
     * ответить на вопрос или отредактировать ответ
     * https://dev.wildberries.ru/ru/openapi/user-communication/#tag/Voprosy/paths/~1api~1v1~1questions/patch
     */
    public function send(): bool
    {
        if(!$this->isExecuteEnvironment())
        {
            return false;
        }

        $url = 'api/v1/feedbacks/questions';

        $response = $this
            ->feedbacks()
            ->TokenHttpClient()
            ->request(
                method: 'PATCH',
                url: $url,
                options: ["json" => [
                    'id' => $this->id,
                    'wasViewed' => true,
                ]]
            );

        $content = $response->getContent(false);

        if($response->getStatusCode() !== 200)
        {
            $this->logger->critical(
                sprintf('wildberries-support: Ошибка отметки вопроса как прочитанного'),
                [
                    $content,
                    self::class.':'.__LINE__,
                ]);

            return false;
        }

        return true;
    }
}