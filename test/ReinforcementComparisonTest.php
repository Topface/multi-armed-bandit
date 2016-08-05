<?php

namespace MultiArmedBandit\Test;

use PHPUnit_Framework_TestCase;
use Predis\Client;
use MultiArmedBandit\ReinforcementComparison;

class ReinforcementComparisonTest extends PHPUnit_Framework_TestCase {

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

    public function testRandomizer() {
        $probs = [10, 10, 5];
        $checks = [0, 0, 0];
        for ($i = 0; $i < 10000; $i++)
            $checks[ReinforcementComparison::getRandomByProbability($probs)]++;

        $this->assertLessThan(0.05 * $checks[0], abs($checks[0] - $checks[1]));
        $this->assertLessThan(0.05 * $checks[0], abs($checks[0] - 2 * $checks[2]));
    }
    
    public function testInitialization() {
        $rc = new ReinforcementComparison($this->predis, $this->hash);

        $rc->initAction('a');

        $this->assertEquals('rr', $rc->getReferenceRewardName());
        $this->assertEquals('p:a', $rc->getPreferenceName('a'));
        $this->assertEquals('ep:a', $rc->getEPreferenceName('a'));
        $this->assertEquals('cc:a', $rc->getChooseCountName('a'));

        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getReferenceRewardName()));
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getPreferenceName('a')));
        $this->assertEquals(1, $this->predis->hget($this->hash, $rc->getEPreferenceName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));


        $rc2 = new ReinforcementComparison($this->predis, $this->hash, 0.1, 9.0, 0.1, 1.0, 'prefix:');
        $rc2->initAction('a');

        $this->assertEquals('prefix:' . $rc->getReferenceRewardName(),      $rc2->getReferenceRewardName());
        $this->assertEquals('prefix:' . $rc->getPreferenceName('a'),        $rc2->getPreferenceName('a'));
        $this->assertEquals('prefix:' . $rc->getEPreferenceName('a'), $rc2->getEPreferenceName('a'));
        $this->assertEquals('prefix:' . $rc->getChooseCountName('a'),         $rc2->getChooseCountName('a'));

        $this->assertEquals(9, $this->predis->hget($this->hash, $rc2->getReferenceRewardName()));
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc2->getPreferenceName('a')));
        $this->assertEquals(1, $this->predis->hget($this->hash, $rc2->getEPreferenceName('a')));
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc2->getChooseCountName('a')));
    }

    public function testOneAction() {
        $rc = new ReinforcementComparison($this->predis, $this->hash, /*rr step*/1.0, /*starting rr*/50.0, /*p step*/0.1, /*t*/1.0);
        $rc->initAction('a');

        // One move, then reward
        $this->assertEquals(0, $rc->getBestActionIndex(['a']));
        $this->assertEquals(1, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $rc->receiveReward('a', 100);

        $eps =1.0/1000/1000;
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $this->assertEquals(100.0, $this->predis->hget($this->hash, $rc->getReferenceRewardName()), '', 100.0*$eps);
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $rc->getPreferenceName('a')), '', 5.0*$eps);
        $this->assertEquals(exp(5.0), $this->predis->hget($this->hash, $rc->getEPreferenceName('a')), '', exp(5.0)*$eps);

        // Two moves, then reward
        $this->assertEquals(0, $rc->getBestActionIndex(['a']));
        $this->assertEquals(1, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $this->assertEquals(0, $rc->getBestActionIndex(['a']));
        $this->assertEquals(2, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $rc->receiveReward('a', 100);

        $eps =1.0/1000/1000;
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $this->assertEquals(50.0, $this->predis->hget($this->hash, $rc->getReferenceRewardName()), '', 50.0*$eps);
        $this->assertEquals(0.0, $this->predis->hget($this->hash, $rc->getPreferenceName('a')), '', $eps);
        $this->assertEquals(exp(0.0), $this->predis->hget($this->hash, $rc->getEPreferenceName('a')), '', exp(0.0)*$eps);

        // 0 moves, reward. This is a corner case, which should happen rarely as move count should be higher than reward count.
        // To resolve this problem we assume that moveCount == 1.
        $rc->receiveReward('a', 100);
        $eps =1.0/1000/1000;
        $this->assertEquals(0, $this->predis->hget($this->hash, $rc->getChooseCountName('a')));
        $this->assertEquals(100.0, $this->predis->hget($this->hash, $rc->getReferenceRewardName()), '', 100.0*$eps);
        $this->assertEquals(5.0, $this->predis->hget($this->hash, $rc->getPreferenceName('a')), '', 5.0*$eps);
        $this->assertEquals(exp(5.0), $this->predis->hget($this->hash, $rc->getEPreferenceName('a')), '', exp(5.0)*$eps);
    }

    public function testThreeFrequentEqualActions() {
        $rc = new ReinforcementComparison($this->predis, $this->hash, 0.1, 20, 0.01, 1.0);
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $rc->initAction($action);

        for ($i = 0; $i < 10000; $i++) {
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('a')) == 2)
                $rc->receiveReward('a', 10);
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('b')) == 2)
                $rc->receiveReward('b', 10);
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('c')) == 1)
                $rc->receiveReward('c', 5);
            $calls[$rc->getBestActionIndex($actions)]++;
        }

        echo "\n";
        echo __CLASS__ . "::" . __FUNCTION__ . "\n";
        echo "Reference reward: " . $this->predis->hget($this->hash, $rc->getReferenceRewardName()) . "\n";
        for ($i = 0; $i < count($calls); $i++) {
            echo "Action $actions[$i]: $calls[$i] times \n";
            echo " preference: " . $this->predis->hget($this->hash, $rc->getPreferenceName($actions[$i])) . "\n";
            echo " e-preference: " . $this->predis->hget($this->hash, $rc->getEPreferenceName($actions[$i])) . "\n";
        }
    }

    public function testThreeRareEqualActions() {
        $rc = new ReinforcementComparison($this->predis, $this->hash, 0.1, 0, 0.01, 1.0);
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $rc->initAction($action);

        for ($i = 0; $i < 10000; $i++) {
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('a')) == 20)
                $rc->receiveReward('a', 10);
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('b')) == 20)
                $rc->receiveReward('b', 10);
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('c')) == 10)
                $rc->receiveReward('c', 5);
            $calls[$rc->getBestActionIndex($actions)]++;
        }

        echo "\n";
        echo __CLASS__ . "::" . __FUNCTION__ . "\n";
        echo "Reference reward: " . $this->predis->hget($this->hash, $rc->getReferenceRewardName()) . "\n";
        for ($i = 0; $i < count($calls); $i++) {
            echo "Action $actions[$i]: $calls[$i] times \n";
            echo " preference: " . $this->predis->hget($this->hash, $rc->getPreferenceName($actions[$i])) . "\n";
            echo " e-preference: " . $this->predis->hget($this->hash, $rc->getEPreferenceName($actions[$i])) . "\n";
        }
    }

    // TODO: why??
    public function testThreeRareNonequalActions() {
        $rc = new ReinforcementComparison($this->predis, $this->hash, 0.1, 50.0, 0.1, 1.0);
        $actions = ['a', 'b', 'c'];
        $calls = [0, 0, 0];
        foreach ($actions as $action)
            $rc->initAction($action);

        ////add "return tostring(deltaReward)" to lua script
        //echo "\n";
        for ($i = 0; $i < 10000; $i++) {
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('a')) == 20)// {
                $reward = $rc->receiveReward('a', 10.0);
//                echo "a $reward\n";
//            }
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('b')) == 10)// {
                $reward = $rc->receiveReward('b', 5.0);
//                echo "b $reward\n";
//            }
            if ($this->predis->hget($this->hash, $rc->getChooseCountName('c')) == 20)// {
                $reward = $rc->receiveReward('c', 5.0);
//                echo "c $reward\n";
//            }
            $calls[$rc->getBestActionIndex($actions)]++;
        }

        echo "\n";
        echo __CLASS__ . "::" . __FUNCTION__ . "\n";
        echo "Reference reward: " . $this->predis->hget($this->hash, $rc->getReferenceRewardName()) . "\n";
        for ($i = 0; $i < count($calls); $i++) {
            echo "Action $actions[$i]: $calls[$i] times \n";
            echo " preference: " . $this->predis->hget($this->hash, $rc->getPreferenceName($actions[$i])) . "\n";
            echo " e-preference: " . $this->predis->hget($this->hash, $rc->getEPreferenceName($actions[$i])) . "\n";
        }
    }

    //TODO: temperature test
}
