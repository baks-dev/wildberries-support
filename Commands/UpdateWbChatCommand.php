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

namespace BaksDev\Wildberries\Support\Commands;

use BaksDev\Core\Messenger\MessageDispatchInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Wildberries\Repository\AllProfileToken\AllProfileTokenInterface;
use BaksDev\Wildberries\Support\Messenger\Schedules\GetWbChatsMessages\GetWbCustomerMessageChatMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Добавляет новые чаты и сообщения для существующих профилей с активными токенами WB
 */
#[AsCommand(
    name: 'baks:wb-support:chat:update',
    description: 'Добавляет/обновляет все чаты и их сообщения'
)]
class UpdateWbChatCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly MessageDispatchInterface $messageDispatch,
        private readonly AllProfileTokenInterface $allWbTokens,
    )
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        /** Идентификаторы профилей пользователей, у которых есть активный токен WB */
        $profiles = $this->allWbTokens
            ->onlyActiveToken()
            ->findAll();

        $profile = $profiles->current();

        $this->update($profile);

        $this->io->success('Чаты успешно обновлены');

        return Command::SUCCESS;
    }

    private function update(UserProfileUid|string $profile): void
    {
        $this->io->note(sprintf('Обновляем профиль %s', $profile->getAttr()));

        $this->messageDispatch->dispatch(
            message: new GetWbCustomerMessageChatMessage($profile)->addAll(),
        );
    }
}