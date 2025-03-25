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

namespace BaksDev\Wildberries\Support\Api\Review\ReviewsList;

use DateTimeImmutable;
use DateTimeZone;

final class WbReviewMessageDTO
{
    /** Идентификатор сообщения. */
    private string $id;

    /** Дата создания сообщения */
    private ?DateTimeImmutable $created;

    /** Массив с текстовым содержание отзыва */
    private string $text;

    /** Форматированное содержание отзыва */
    private string $data;

    /** Имя пользователя */
    private string $userName;

    /** Заголовок отзыва */
    private string $title;

    /** Оценка товара */
    private int $valuation;

    /** Есть ли текст у отзыва */
    private bool $isText = false;

    public function __construct(array $data)
    {
        $this->id = (string) $data['id'];

        $moscowTimezone = new DateTimeZone(date_default_timezone_get());
        $this->created = new DateTimeImmutable($data['createdDate'])->setTimezone($moscowTimezone);

        $this->text = $data['text'];

        $this->valuation = $data['productValuation'];

        $this->data = $this->formatData($data);

        $this->userName = $data['userName'];

        $this->title = 'Отзыв к товару '.$data['productDetails']['productName'];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreated(): ?DateTimeImmutable
    {
        return $this->created;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getName(): string
    {
        return $this->userName;
    }

    public function formatData(array $data): string
    {
        $article = $data['productDetails']['nmId'];
        $formattedData = '<div class="d-flex align-items-center gap-1 text-primary pointer copy small" data-copy="'.$article.'">
                    <svg version="1.1" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="14" height="14" fill="currentColor" viewBox="0 0 115.77 122.88">
                        <path d="M89.62,13.96v7.73h12.19h0.01v0.02c3.85,0.01,7.34,1.57,9.86,4.1c2.5,2.51,4.06,5.98,4.07,9.82h0.02v0.02 v73.27v0.01h-0.02c-0.01,3.84-1.57,7.33-4.1,9.86c-2.51,2.5-5.98,4.06-9.82,4.07v0.02h-0.02h-61.7H40.1v-0.02 c-3.84-0.01-7.34-1.57-9.86-4.1c-2.5-2.51-4.06-5.98-4.07-9.82h-0.02v-0.02V92.51H13.96h-0.01v-0.02c-3.84-0.01-7.34-1.57-9.86-4.1 c-2.5-2.51-4.06-5.98-4.07-9.82H0v-0.02V13.96v-0.01h0.02c0.01-3.85,1.58-7.34,4.1-9.86c2.51-2.5,5.98-4.06,9.82-4.07V0h0.02h61.7 h0.01v0.02c3.85,0.01,7.34,1.57,9.86,4.1c2.5,2.51,4.06,5.98,4.07,9.82h0.02V13.96L89.62,13.96z M79.04,21.69v-7.73v-0.02h0.02 c0-0.91-0.39-1.75-1.01-2.37c-0.61-0.61-1.46-1-2.37-1v0.02h-0.01h-61.7h-0.02v-0.02c-0.91,0-1.75,0.39-2.37,1.01 c-0.61,0.61-1,1.46-1,2.37h0.02v0.01v64.59v0.02h-0.02c0,0.91,0.39,1.75,1.01,2.37c0.61,0.61,1.46,1,2.37,1v-0.02h0.01h12.19V35.65 v-0.01h0.02c0.01-3.85,1.58-7.34,4.1-9.86c2.51-2.5,5.98-4.06,9.82-4.07v-0.02h0.02H79.04L79.04,21.69z M105.18,108.92V35.65v-0.02 h0.02c0-0.91-0.39-1.75-1.01-2.37c-0.61-0.61-1.46-1-2.37-1v0.02h-0.01h-61.7h-0.02v-0.02c-0.91,0-1.75,0.39-2.37,1.01 c-0.61,0.61-1,1.46-1,2.37h0.02v0.01v73.27v0.02h-0.02c0,0.91,0.39,1.75,1.01,2.37c0.61,0.61,1.46,1,2.37,1v-0.02h0.01h61.7h0.02 v0.02c0.91,0,1.75-0.39,2.37-1.01c0.61-0.61,1-1.46,1-2.37h-0.02V108.92L105.18,108.92z"></path>
                    </svg>
                    Артикул: '.$article.'
                </div>';
        
        $formattedData = str_replace(PHP_EOL, '', $formattedData);
        
        $formattedData .= '<p> Оценка товара: '.$this->valuation.'</p>';
        
        if(!empty($data['text']))
        {
            $formattedData .= '<p>'.$data['text'].'</p>';
        }
        if(!empty($data['pros']))
        {
            $formattedData .= '<p> Достоинства: '.$data['pros'].'</p>';
        }
        if(!empty($data['cons']))
        {
            $formattedData .= '<p> Недостатки: '.$data['cons'].'</p>';
        }
        if(isset($data['photo']))
        {
            foreach($data['photoLinks'] as $photo)
            {
                $imageSrc = $photo['fullSize'];

                $info = pathinfo($imageSrc);
                $extension = $info['extension'];

                try {
                    $content = file_get_contents($imageSrc);
                    $content = base64_encode($content);

                    $formattedData .= '<a href="'.$imageSrc.'" target="_blank">
                        <img src="data:image/'.$extension.';base64,'.$content.'" style="max-width: 100px;">
                    </a>';
                }
                catch(\Exception $e) {}
            }
        }

        /* todo Модуль для видеоплеера */
        if(isset($data['video']))
        {
            $imageSrc = $data['video']['previewImage'];
            $videoSrc = 'https://seller.wildberries.ru/feedbacks/feedbacks-tab/not-answered?feedbacks-module_searchValue='.$data['productDetails']['nmId'];

            $info = pathinfo($imageSrc);
            $extension = $info['extension'];

            $content = file_get_contents($imageSrc);
            $content = base64_encode($content);

            $formattedData .= '<a href="'.$videoSrc.'" target="_blank">
                        <img src="data:image/'.$extension.';base64,'.$content.'" style="max-width: 100px;">
                    </a>';
        }

        if(isset($data['text']) || isset($data['pros']) || isset($data['cons']))
        {
            $this->isText = true;
        }

        return $formattedData;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getValuation(): int
    {
        return $this->valuation;
    }

    public function getIsText(): bool
    {
        return $this->isText;
    }
}