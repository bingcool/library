<?php

//前序遍历生成二叉树
function createBinTree($data = 1)
{
    $binTree = new \stdClass();
    $binTree->data = $data;
    $binTree->left = null;
    $binTree->right = null;
    return $binTree;
}

$rootNode1 = createBinTree(1);
$rootNode2 = createBinTree(2);
$rootNode3 = createBinTree(3);

$rootNode1->left = $rootNode2;
$rootNode1->right = $rootNode3;

$rootNode4 = createBinTree(4);
$rootNode5 = createBinTree(5);
$rootNode6 = createBinTree(6);
$rootNode7 = createBinTree(7);

$rootNode2->left = $rootNode4;
$rootNode2->right = $rootNode5;

$rootNode3->left = $rootNode6;
$rootNode3->right = $rootNode7;

$rootNode9 = createBinTree(9);

$rootNode6->left = $rootNode9;

$leftArr = [];

// 前序遍历
function viewLeft($node, &$leftArr, $level)
{
    if ($node == null) {
        return;
    }

    $leftArr[] = $node->data;

    // left node
    $nextLeftNode = $node->left;
    $nextRightNode = $node->right;
    //
    viewLeft($nextLeftNode, $leftArr, $level++);
    //
    viewLeft($nextRightNode, $leftArr, $level++);

}

viewLeft($rootNode1, $leftArr, 1);
//var_dump($leftArr);

// 层级遍历
function levelorder($node)
{
    $data = [];
    if (!($node instanceof \stdClass)) {
        return $data;
    }

    $level = 0;
    $node->level = $level;

    $queue = [$node]; // 临时处理队列, 把开始节点放进队列
    while (!empty($queue)) {
        $node = array_shift($queue); // 出队
        $level = $node->level;

        $data[$level][] = $node->data;

        if ($node->left || $node->right) {
            $level++;
        }

        if ($node->left) {
            $node->left->level = $level;
            array_push($queue, $node->left);
        }

        if ($node->right) {
            $node->right->level = $level;
            array_push($queue, $node->right);
        }
    }

    return $data;
}

$result = levelorder($rootNode1);

var_dump($result);








