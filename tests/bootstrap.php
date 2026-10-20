<?php

require __DIR__ . '/../vendor/autoload.php';

// Psalm's test classes extend classes that are final outside of its test suite.
DG\BypassFinals::enable();
