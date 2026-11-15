#!/bin/sh
# Builds build/aushadhbox-sync.jar and runs the tests. Needs only a JDK 17 or newer.
set -e
cd "$(dirname "$0")"
rm -rf build && mkdir -p build/classes build/test
javac --release 17 -d build/classes src/main/java/in/aushadhbox/sync/*.java
javac --release 17 -cp build/classes -d build/test src/test/java/in/aushadhbox/sync/*.java
java -Dstdout.encoding=UTF-8 -cp build/classes:build/test in.aushadhbox.sync.SyncAgentTest
printf "Main-Class: in.aushadhbox.sync.SyncAgent\n" > build/manifest.txt
jar cfm build/aushadhbox-sync.jar build/manifest.txt -C build/classes .
echo "Built build/aushadhbox-sync.jar"
