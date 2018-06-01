<?php
/**
 * Statusengine Worker
 * Copyright (C) 2016-2017  Daniel Ziegler
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Statusengine;

use Statusengine\Config\WorkerConfig;
use Statusengine\QueueingEngines\QueueingEngine;
use Statusengine\QueueingEngines\QueueInterface;
use Statusengine\ValueObjects\Hoststatus;
use Statusengine\ValueObjects\Pid;
use Statusengine\Redis\Statistics;

class HoststatusChild extends Child {

    /**
     * @var QueueInterface
     */
    private $Queue;

    /**
     * @var WorkerConfig
     */
    private $HoststatusConfig;

    /**
     * @var Config
     */
    private $Config;

    /**
     * @var Redis
     */
    private $HoststatusRedis;

    /**
     * @var ChildSignalHandler
     */
    private $SignalHandler;

    /**
     * @var Statistics
     */
    private $Statistics;

    /**
     * @var HoststatusList
     */
    private $HoststatusList;

    /**
     * @var StorageBackend
     */
    private $StorageBackend;

    /**
     * @var bool
     */
    private $isRedisEnabled;

    /**
     * @var bool
     */
    private $storeLiveDateInArchive;

    /**
     * @var Syslog
     */
    private $Syslog;

    /**
     * @var QueueingEngine
     */
    private $QueueingEngine;

    /**
     * HoststatusChild constructor.
     * @param Config $Config
     * @param Pid $Pid
     * @param Syslog $Syslog
     */
    public function __construct(
        Config $Config,
        Pid $Pid,
        Syslog $Syslog
    ) {
        $this->Config = $Config;
        $this->parentPid = $Pid->getPid();
        $this->Syslog = $Syslog;
    }

    public function setup(){
        $this->SignalHandler = new ChildSignalHandler();
        $this->HoststatusConfig = new \Statusengine\Config\Hoststatus();
        $this->Statistics = new Statistics($this->Config, $this->Syslog);

        $BulkConfig = $this->Config->getBulkSettings();
        $BulkInsertObjectStore = new \Statusengine\BulkInsertObjectStore(
            $BulkConfig['max_bulk_delay'],
            $BulkConfig['number_of_bulk_records']
        );
        $BackendSelector = new BackendSelector($this->Config, $BulkInsertObjectStore, $this->Syslog);
        $this->StorageBackend = $BackendSelector->getStorageBackend();


        $this->isRedisEnabled = $this->Config->isRedisEnabled();
        $this->storeLiveDateInArchive = $this->Config->storeLiveDateInArchive();

        $this->SignalHandler->bind();

        $this->QueueingEngine = new QueueingEngine($this->Config, $this->HoststatusConfig);
        $this->Queue = $this->QueueingEngine->getQueue();
        $this->Queue->connect();


        $this->HoststatusRedis = new \Statusengine\Redis\Redis($this->Config, $this->Syslog);
        $this->HoststatusRedis->connect();

        $this->HoststatusList = new HoststatusList($this->HoststatusRedis);
    }

    public function loop() {
        $this->Statistics->setPid($this->Pid);
        $StatisticType = new Config\StatisticType();
        $StatisticType->isHoststatusStatistic();
        $this->Statistics->setStatisticType($StatisticType);

        if ($this->storeLiveDateInArchive) {
            $this->StorageBackend->connect();
        }

        while (true) {
            $jobData = $this->Queue->getJob();
            if ($jobData !== null) {
                $Hoststatus = new Hoststatus($jobData);

                //Only save records that stay for more than 5 minutes in the queue
                if ($Hoststatus->getStatusUpdateTime() < (time() - 500)) {
                    continue;
                }

                if ($this->isRedisEnabled) {
                    $this->HoststatusRedis->save(
                        $Hoststatus->getKey(),
                        $Hoststatus->serialize(),
                        $Hoststatus->getExpires()
                    );
                    $this->HoststatusList->updateList($Hoststatus);
                }

                if ($this->storeLiveDateInArchive) {
                    $this->StorageBackend->saveHoststatus(
                        $Hoststatus
                    );
                }

                $this->Statistics->increase();
            }

            if ($this->storeLiveDateInArchive) {
                $this->StorageBackend->dispatch();
            }

            $this->Statistics->dispatch();

            $this->SignalHandler->dispatch();
            if ($this->SignalHandler->shouldExit()) {
                $this->Queue->disconnect();
                exit(0);
            }
            $this->checkIfParentIsAlive();
        }
    }
}
