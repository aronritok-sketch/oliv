#!/bin/bash
# The whole suite on a site, in three parallel parts (by device), then the report with the link check.
# usage: bash run-all.sh <base> <tag>
BASE=$1; TAG=$2
cd "$(dirname "$0")"
node suite.js "$BASE" "r-$TAG-a.json" 0,1,2,3 2> "log-$TAG-a.txt" &
node suite.js "$BASE" "r-$TAG-b.json" 4,5,6,7 2> "log-$TAG-b.txt" &
node suite.js "$BASE" "r-$TAG-c.json" 8,9,10 2> "log-$TAG-c.txt" &
wait
node report.js "$BASE" "r-$TAG-a.json" "r-$TAG-b.json" "r-$TAG-c.json" > "report-$TAG.txt" 2>&1
head -1 "report-$TAG.txt"
