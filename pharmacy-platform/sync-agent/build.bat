@echo off
rem Builds build\aushadhbox-sync.jar and runs the tests. Needs only a JDK 17 or newer (e.g. Eclipse Temurin).
cd /d %~dp0
if exist build rmdir /s /q build
mkdir build\classes build\test
javac --release 17 -d build\classes src\main\java\in\aushadhbox\sync\*.java || exit /b 1
javac --release 17 -cp build\classes -d build\test src\test\java\in\aushadhbox\sync\*.java || exit /b 1
java -cp build\classes;build\test in.aushadhbox.sync.SyncAgentTest || exit /b 1
echo Main-Class: in.aushadhbox.sync.SyncAgent> build\manifest.txt
jar cfm build\aushadhbox-sync.jar build\manifest.txt -C build\classes .
echo Built build\aushadhbox-sync.jar
