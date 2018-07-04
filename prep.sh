#!/bin/bash
cd `dirname ${BASH_SOURCE[0]}`
BASE_DIR=`pwd`
#install google API client
if [ ! -d "$BASE_DIR/vendor/google" ];
then
        composer require google/apiclient:^2.0
fi
