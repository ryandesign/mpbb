#!/bin/bash

./make-list-of-distfiles.php "$@" | ./download-those-distfiles.php
