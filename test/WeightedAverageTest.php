<?php

namespace MultiArmedBandit\Test;

use PHPUnit_Framework_TestCase;
use Predis\Client;
use MultiArmedBandit\WeightedAverage;

class WeightedAverageTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Client
     */
    private $predis;

    /**
     * @var string
     */
    private $hash = '__testInit__';

    protected function setUp() {
        $this->predis = new Client();
        $this->hash = '__testInit__';
        $this->predis->del($this->hash);
    }

    protected function tearDown() {
        $this->predis->del($this->hash);
    }

    public function testInitialization() {
        $wa = new WeightedAverage($this->predis, $this->hash, 0.9, 0.1, 50, '');

        $wa->initAction('a');
        $this->assertEquals('v:a', $wa->getValueName('a'));
        $this->assertEquals('cc:a', $wa->getChooseCountName('a'));
        $this->assertEquals('sr:a', $wa->getStoredRewardName('a'));
        $this->assertEquals(50, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
    }

    public function testOneAction() {
        $wa = new WeightedAverage($this->predis, $this->hash, 0.9, 0.1, 50, '');
        $wa->initAction('a');
        $eps = 1.0 / 1000 / 1000;

        // 0 moves, reward. This is a corner case, which should happen rarely as move count should be higher than reward count.
        // if `chooseCount` == 0 we store reward to `storedReward`, until next receiveReward() call
        $wa->receiveReward('a', 75);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(50, $this->predis->hget($this->hash, $wa->getValueName('a')), '', 50 * $eps);

        // one move, then reward
        $this->assertEquals(0, $wa->getBestActionIndex(['a']));
        $this->assertEquals(1, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $wa->receiveReward('a', 75);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(60.0, $this->predis->hget($this->hash, $wa->getValueName('a')), '', 60.0 * $eps);

        // Two moves, then reward
        $this->assertEquals(0, $wa->getBestActionIndex(['a']));
        $this->assertEquals(1, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $wa->getBestActionIndex(['a']));
        $this->assertEquals(2, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));

        $wa->receiveReward('a', 320);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(70.0, $this->predis->hget($this->hash, $wa->getValueName('a')), '', 70.0 * $eps);
    }

    public function testGreediness() {
        $greediness = 0.9;
        $wa = new WeightedAverage($this->predis, $this->hash, $greediness, 0.1, 20, '');
        $actions = ['a', 'b'];
        $calls = [0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);
        $this->predis->hset($this->hash, $wa->getValueName('a'), 100);
        $this->predis->hset($this->hash, $wa->getValueName('a'), 1);

        for ($i = 0; $i < 10000; $i++)
            $calls[$wa->getBestActionIndex($actions)]++;

        $showCount = (10000 + 10000 * $greediness) / 2; // a + (1-a)/2 == (a+1)/2 == 9500
        $this->assertEquals($showCount, $calls[1], '', 0.05 * $showCount);

        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);
    }

    // TODO: write initActions()
    public function testThreeEqualActions() {
        $wa = new WeightedAverage($this->predis, $this->hash, 0.9, 0.1, 20, '');
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);

        for ($i = 0; $i < 10000; $i++) {
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('a')) == 2)
                $wa->receiveReward('a', 10);
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('b')) == 2)
                $wa->receiveReward('b', 10);
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('c')) == 1)
                $wa->receiveReward('c', 5);
            $calls[$wa->getBestActionIndex($actions)]++;
        }

        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);
    }

    public function testThreeNonequalActions() {
        $wa = new WeightedAverage($this->predis, $this->hash, 0.9, 0.1, 20, '');
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);

        for ($i = 0; $i < 10000; $i++) {
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('a')) == 2)
                $wa->receiveReward('a', 10);
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('b')) == 2)
                $wa->receiveReward('b', 5);
            if ($this->predis->hget($this->hash, $wa->getChooseCountName('c')) == 1)
                $wa->receiveReward('c', 5);
            $calls[$wa->getBestActionIndex($actions)]++;
        }

        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);
    }

    /**
     * @param $wa WeightedAverage
     * @param $calls []
     * @param $actions []
     * @param $methodName string
     */
    private function EchoStats($wa, $calls, $actions, $methodName) {
        echo "\n";
        echo __CLASS__ . "::" . $methodName . "\n";
        for ($i = 0; $i < count($calls); $i++) {
            echo "Action $actions[$i]: $calls[$i] times \n";
            echo " value: " . $this->predis->hget($this->hash, $wa->getValueName($actions[$i])) . "\n";
        }
    }
}