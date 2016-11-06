<?php

namespace MultiArmedBandit\Test;

use PHPUnit_Framework_TestCase;
use Predis\Client;
use MultiArmedBandit\EGreedyDiscounted;

class EGreedyDiscountedTest extends PHPUnit_Framework_TestCase {

    /**
     * @var Client
     */
    private $predis;

    /**
     * @var string
     */
    private $hash;

    protected function setUp() {
        $this->predis   = new Client();
        $this->hash     = '__testInit__';
        $this->predis->del($this->hash);
    }

    protected function tearDown() {
        $this->predis->del($this->hash);
    }

    public function testInitialization() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', 0.1, 0.1, 50, 1);

        $wa->initAction('a');
        $this->assertEquals('v:a', $wa->getValueName('a'));
        $this->assertEquals('cc:a', $wa->getChooseCountName('a'));
        $this->assertEquals('sr:a', $wa->getStoredRewardName('a'));
        $this->assertEquals(50, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
    }

    public function testOneAction() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', 0.1, 0.1, 0, 1);
        $wa->initAction('a');

        $wa->receiveReward('a', 50);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(50, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $wa->receiveReward('a', 50);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(100, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(1, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(10, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(2, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(9, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));
    }

    public function testOneActionBulk() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', 0.9, 0.1, 0, 2);
        $wa->initAction('a');

        // two actions with reward
        $wa->receiveReward('a', 100);
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(100, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(1, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(100, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $wa->receiveReward('a', 100);
        $this->assertEquals(1, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(200, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(2, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(10, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        // 2 actions without rewards
        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(3, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(10, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));

        $i = $wa->getBestActionIndex(['a']);
        $this->assertEquals(0, $i);
        $this->assertEquals(4, $this->predis->hget($this->hash, $wa->getChooseCountName('a')));
        $this->assertEquals(9, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $wa->getStoredRewardName('a')));
    }

    public function testGreediness() {
        $explorationRate = 0.1;
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', $explorationRate, 0.1, 20, 10000);
        $actions = ['a', 'b'];
        $calls = [0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);
        $this->predis->hset($this->hash, $wa->getValueName('a'), 100);
        $this->predis->hset($this->hash, $wa->getValueName('a'), 1);

        for ($i = 0; $i < 10000; $i++)
            $calls[$wa->getBestActionIndex($actions)]++;
        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);

        $chooseCount = 10000 * (2 - $explorationRate) / 2; // 1-a + a/2 == (2-a)/2. 9500
        $this->assertEquals($chooseCount, $calls[1], '', 0.05 * $chooseCount);
    }

    // TODO: write initActions()
    public function testThreeEqualActions() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', 0.1, 0.1, 20, 2);
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);

        for ($i = 0; $i < 10000; $i++) {
            $ai = $wa->getBestActionIndex($actions);
            $calls[$ai]++;
            switch ($ai) {
            case 0:
                if ($this->predis->hget($this->hash, $wa->getChooseCountName('a')) % 2 == 1)
                    $wa->receiveReward('a', 10);    // reward every 2 actions
                break;
            case 1:
                if ($this->predis->hget($this->hash, $wa->getChooseCountName('b')) % 2 == 1)
                    $wa->receiveReward('b', 10);    // reward every 2 actions
                break;
            case 2:
                $wa->receiveReward('c', 5);         // small reward every action
                break;
            }
        }

        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $wa->getValueName('b')));
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $wa->getValueName('c')));
    }

    public function testThreeNonequalActions() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, '', 0.1, 0.1, 20, 2);
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $wa->initAction($action);

        for ($i = 0; $i < 15000; $i++) {
            $ai = $wa->getBestActionIndex($actions);
            $calls[$ai]++;
            switch ($ai) {
            case 0:
                if ($this->predis->hget($this->hash, $wa->getChooseCountName('a')) % 2 == 1)
                    $wa->receiveReward('a', 10);    // reward every 2 actions
                break;
            case 1:
                if ($this->predis->hget($this->hash, $wa->getChooseCountName('b')) % 2 == 1)
                    $wa->receiveReward('b', 5);    // small reward every 2 actions
                break;
            case 2:
                $wa->receiveReward('c', 5);         // small reward every action
                break;
            }
        }

        $this->EchoStats($wa, $calls, $actions, __FUNCTION__);
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $wa->getValueName('a')));
        $this->assertEquals(2.5, $this->predis->hget($this->hash, $wa->getValueName('b')));
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $wa->getValueName('c')));
    }

    public function testGetActionState() {
        $wa = new EGreedyDiscounted($this->predis, $this->hash, 'itsaprefix', 0.1, 0.1, 50, 1);

        $wa->initAction('a');
        $actionState = $wa->getActionState('a');
        $this->assertEquals(2, count($actionState));
        $this->assertEquals(50.0, $actionState['weightedAverage']);
        $this->assertEquals(0, $actionState['chooseCount']);

        $wa->getBestActionIndex(['a']);
        $wa->getBestActionIndex(['a']);
        $wa->getBestActionIndex(['a']);
        $actionState = $wa->getActionState('a');
        $this->assertEquals(2, count($actionState));
        $this->assertLessThan(50.0, $actionState['weightedAverage']);
        $this->assertEquals(3, $actionState['chooseCount']);
    }

    /**
     * @param EGreedyDiscounted   $wa
     * @param array                     $calls
     * @param array                     $actions
     * @param string                    $methodName
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
