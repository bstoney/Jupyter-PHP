<?php

/*
 * This file is part of Jupyter-PHP.
 *
 * (c) 2015-2017 Litipk
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Litipk\JupyterPHP\Handlers;

use Litipk\JupyterPHP\Actions\ExecuteAction;
use Litipk\JupyterPHP\Actions\HistoryAction;
use Litipk\JupyterPHP\Actions\KernelInfoAction;
use Litipk\JupyterPHP\Actions\ShutdownAction;
use Litipk\JupyterPHP\JupyterBroker;

use Litipk\JupyterPHP\KernelOutput;
use Monolog\Logger;
use Psy\Shell;
use React\ZMQ\SocketWrapper;

final class ShellMessagesHandler
{
    /** @var ExecuteAction */
    private $executeAction;

    /** @var HistoryAction */
    private $historyAction;

    /** @var KernelInfoAction */
    private $kernelInfoAction;

    /** @var ShutdownAction */
    private $shutdownAction;

    /** @var Shell */
    private $shellSoul;

    /** @var Logger */
    private $logger;


    public function __construct(
        JupyterBroker $broker,
        SocketWrapper $iopubSocket,
        SocketWrapper $shellSocket,
        Logger $logger
    ) {
        $this->shellSoul = new Shell();

        $this->executeAction = new ExecuteAction($broker, $iopubSocket, $shellSocket, $this->shellSoul);
        $this->historyAction = new HistoryAction($broker, $shellSocket);
        $this->kernelInfoAction = new KernelInfoAction($broker, $shellSocket, $iopubSocket);
        $this->shutdownAction = new ShutdownAction($broker, $iopubSocket, $shellSocket);

        $this->logger = $logger;

        $broker->send(
            $iopubSocket, 'status', ['execution_state' => 'starting'], []
        );

        $this->shellSoul->setOutput(new KernelOutput($this->executeAction, $this->logger->withName('KernelOutput')));
    }

    public function __invoke(array $msg)
    {
        list($zmqId, $delim, $hmac, $header, $parentHeader, $metadata, $content) = $msg;

        $header = json_decode($header, true);
        $content = json_decode($content, true);

        $this->logger->debug('Received message', [
            'processId' => getmypid(),
            'zmqId' => htmlentities($zmqId, ENT_COMPAT, "UTF-8"),
            'delim' => $delim,
            'hmac' => $hmac,
            'header' => $header,
            'parentHeader' => $parentHeader,
            'metadata' => $metadata,
            'content' => $content
        ]);

        if ('kernel_info_request' === $header['msg_type']) {
            $this->kernelInfoAction->call($header, $content, $zmqId);
        } elseif ('execute_request' === $header['msg_type']) {
            $this->executeAction->call($header, $content, $zmqId);
        } elseif ('history_request' === $header['msg_type']) {
            $this->historyAction->call($header, $content, $zmqId);
        } elseif ('shutdown_request' === $header['msg_type']) {
            $this->shutdownAction->call($header, $content, $zmqId);
        } elseif ('comm_open' === $header['msg_type']) {
            // TODO: Research about what should be done.
        } else {
            $this->logger->error('Unknown message type', ['processId' => getmypid(), 'header' => $header]);
        }
    }
}
