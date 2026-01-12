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

namespace BaksDev\Wildberries\Support\Api\Chat\ChatsMessages;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class WbChatMessageDTO
{
    /** Идентификатор сообщения. */
    private string $id;

    /** Идентификатор чата */
    private string $chatId;

    /** Идентификатор участника чата. */
    private string|false $userId;

    /** Идентификатор продукта в селлере */
    private int|false $nomenclature;

    /** Тип участника чата: client, seller или wb */
    private string $userType;

    /** Имя участника чата. */
    private string $userName;

    /** Дата создания сообщения */
    private ?DateTimeImmutable $created;

    /** Содержимое сообщения  */
    private string $data;

    /** Текстовое содержимое сообщения */
    private string|false $text;

    /** Является ли первым сообщением в чате  */
    private bool $isNewChat = false;

    public function __construct(array $data)
    {
        $this->id = isset($data['replySign']) ? (string) $data['replySign'] : (string) $data['eventID'];
        $this->chatId = (string) $data['chatID'];
        $this->userId = isset($data['clientID']) ? $data['clientID'] : false;
        $this->userType = (string) $data['sender'];
        $this->userName = $data['clientName'] ?? '';

        $this->nomenclature = $data['message']['attachments']['goodCard']['nmID'] ?? false;

        $timezone = new DateTimeZone(date_default_timezone_get());
        $this->created = new DateTimeImmutable($data['addTime'])->setTimezone($timezone);

        $this->text = $data['message']['text'] ?? false;
        $this->data = $this->formatData($data);
        $this->isNewChat = isset($data['isNewChat']) && $data['isNewChat'];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function getUserId(): string|false
    {
        return $this->userId;
    }

    public function getUserType(): string
    {
        return $this->userType;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getCreated(): ?DateTimeImmutable
    {
        return $this->created;
    }

    public function getNomenclature(): false|int
    {
        return $this->nomenclature;
    }


    public function formatData(array $data): string
    {
        $formattedData = '';

        /** Если прикреплена карточка товара - добавляем ссылку для копирования артикула */
        /*if(isset($data['message']['attachments']['goodCard']))
        {
            $article = $data['message']['attachments']['goodCard']['nmID'];
            $formattedData .= '<div class="d-flex align-items-center gap-1 text-primary pointer copy small" data-copy="'.$article.'">
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="14" height="14" fill="currentColor" viewBox="0 0 115.77 122.88">
                        <path d="M89.62,13.96v7.73h12.19h0.01v0.02c3.85,0.01,7.34,1.57,9.86,4.1c2.5,2.51,4.06,5.98,4.07,9.82h0.02v0.02 v73.27v0.01h-0.02c-0.01,3.84-1.57,7.33-4.1,9.86c-2.51,2.5-5.98,4.06-9.82,4.07v0.02h-0.02h-61.7H40.1v-0.02 c-3.84-0.01-7.34-1.57-9.86-4.1c-2.5-2.51-4.06-5.98-4.07-9.82h-0.02v-0.02V92.51H13.96h-0.01v-0.02c-3.84-0.01-7.34-1.57-9.86-4.1 c-2.5-2.51-4.06-5.98-4.07-9.82H0v-0.02V13.96v-0.01h0.02c0.01-3.85,1.58-7.34,4.1-9.86c2.51-2.5,5.98-4.06,9.82-4.07V0h0.02h61.7 h0.01v0.02c3.85,0.01,7.34,1.57,9.86,4.1c2.5,2.51,4.06,5.98,4.07,9.82h0.02V13.96L89.62,13.96z M79.04,21.69v-7.73v-0.02h0.02 c0-0.91-0.39-1.75-1.01-2.37c-0.61-0.61-1.46-1-2.37-1v0.02h-0.01h-61.7h-0.02v-0.02c-0.91,0-1.75,0.39-2.37,1.01 c-0.61,0.61-1,1.46-1,2.37h0.02v0.01v64.59v0.02h-0.02c0,0.91,0.39,1.75,1.01,2.37c0.61,0.61,1.46,1,2.37,1v-0.02h0.01h12.19V35.65 v-0.01h0.02c0.01-3.85,1.58-7.34,4.1-9.86c2.51-2.5,5.98-4.06,9.82-4.07v-0.02h0.02H79.04L79.04,21.69z M105.18,108.92V35.65v-0.02 h0.02c0-0.91-0.39-1.75-1.01-2.37c-0.61-0.61-1.46-1-2.37-1v0.02h-0.01h-61.7h-0.02v-0.02c-0.91,0-1.75,0.39-2.37,1.01 c-0.61,0.61-1,1.46-1,2.37h0.02v0.01v73.27v0.02h-0.02c0,0.91,0.39,1.75,1.01,2.37c0.61,0.61,1.46,1,2.37,1v-0.02h0.01h61.7h0.02 v0.02c0.91,0,1.75-0.39,2.37-1.01c0.61-0.61,1-1.46,1-2.37h-0.02V108.92L105.18,108.92z"></path>
                    </svg>
                    Артикул: '.$article.'
                </div>';

            $formattedData = str_replace(PHP_EOL, '', $formattedData);
        }*/

        /** Добавляем текст сообщения */
        if(isset($data['message']['text']))
        {
            $formattedData .= '<p>'.$data['message']['text'].'</p>';
        }

        /** Добавляем ссылку на прикрепленный файл */
        if(isset($data['message']['attachments']['files']))
        {
            foreach($data['message']['attachments']['files'] as $file)
            {
                $fileName = $file['name'];
                $fileSrc = $file['url'];
                $formattedData .= '<a href="'.$fileSrc.'" target="_blank">'.$fileName.'</a>';
            }
        }

        /** Добавляем превью изображения с ссылкой на него */
        if(isset($data['message']['attachments']['images']))
        {
            foreach($data['message']['attachments']['images'] as $image)
            {
                $imageSrc = $image['url'];

                $info = pathinfo($imageSrc);
                $extension = $info['extension'];

                try
                {
                    $content = file_get_contents($imageSrc);
                    $content = base64_encode($content);

                    $formattedData .= '<a href="'.$imageSrc.'" target="_blank"><img src="data:image/'.$extension.';base64,'.$content.'" style="max-width: 100px;"></a>';
                }
                catch(Exception)
                {
                }
            }
        }

        return $formattedData;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function isNewChat(): bool
    {
        return $this->isNewChat;
    }

    public function getText(): string|bool
    {
        return $this->text;
    }
}