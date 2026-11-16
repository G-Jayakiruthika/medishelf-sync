package in.aushadhbox.sync;

import java.io.IOException;
import java.io.InputStream;
import java.io.Reader;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.Charset;
import java.nio.charset.CharsetDecoder;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.nio.file.*;
import java.security.MessageDigest;
import java.time.Duration;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.*;

/**
 * AushadhBox Pharmacy sync agent.
 *
 * Runs on the shop's billing computer. Billing software (Marg, GoFrugal, Medeil, Tally...) exports the closing
 * stock as CSV into a folder, often every hour. The agent notices a new or changed export, waits until the file has
 * finished writing, converts it to UTF-8 and uploads it to the shop's AushadhBox Pharmacy account, so customers can
 * see "in stock / few left" and linked pharmacies can find medicines.
 *
 * Built only on the standard JDK (17+): no libraries to install. Works offline: uploads are kept in a queue folder
 * and retried with back-off when the internet comes back. The token is a per-computer secret made in the portal
 * (Stock → Billing software sync) and can be revoked there.
 *
 *   java -jar aushadhbox-sync.jar sync.properties          (keeps running)
 *   java -jar aushadhbox-sync.jar sync.properties --once   (one check, for Windows Task Scheduler)
 */
public final class SyncAgent {
    static final String VERSION = "1.0.0";

    private final Config cfg;
    private final Uploader uploader;
    private final Path stateFile;
    private final Path queueDir;
    private final Properties state = new Properties();

    public SyncAgent(Config cfg, Uploader uploader) throws IOException {
        this.cfg = cfg;
        this.uploader = uploader;
        this.stateFile = cfg.dataDir.resolve("state.properties");
        this.queueDir = cfg.dataDir.resolve("queue");
        Files.createDirectories(queueDir);
        if (Files.exists(stateFile)) try (Reader r = Files.newBufferedReader(stateFile)) { state.load(r); }
    }

    /** One pass: send what is waiting in the queue, then look for new exports. Returns the number uploaded. */
    public int runOnce() throws IOException {
        int sent = flushQueue();
        for (Path f : candidates()) {
            String hash = sha256(Files.readAllBytes(f));
            String key = "sent." + f.getFileName();
            if (hash.equals(state.getProperty(key))) continue;          // unchanged since the last upload
            if (!isStable(f)) continue;                                   // billing software is still writing it
            String csv = readAsUtf8(f);
            if (csv.lines().count() < 2) { log("skip " + f.getFileName() + ": no rows"); state.setProperty(key, hash); continue; }
            Path q = queueDir.resolve(System.currentTimeMillis() + "-" + f.getFileName().toString().replaceAll("[^A-Za-z0-9._-]", "_") + ".q");
            Files.writeString(q, csv, StandardCharsets.UTF_8);
            state.setProperty(key, hash);
            saveState();
            log("queued " + f.getFileName() + " (" + (csv.lines().count() - 1) + " rows)");
        }
        sent += flushQueue();
        return sent;
    }

    /** Export files in the watched folder, newest last. */
    List<Path> candidates() throws IOException {
        if (!Files.isDirectory(cfg.folder)) { log("folder not found: " + cfg.folder); return List.of(); }
        PathMatcher m = FileSystems.getDefault().getPathMatcher("glob:" + cfg.pattern);
        List<Path> out = new ArrayList<>();
        try (DirectoryStream<Path> ds = Files.newDirectoryStream(cfg.folder)) {
            for (Path p : ds) if (Files.isRegularFile(p) && m.matches(p.getFileName()) && Files.size(p) <= 8L * 1024 * 1024) out.add(p);
        }
        out.sort(Comparator.comparing(p -> { try { return Files.getLastModifiedTime(p); } catch (IOException e) { return FileTime0.ZERO; } }));
        return out;
    }

    /** A file is complete when its size and time have not changed for `settleSeconds`. */
    boolean isStable(Path f) throws IOException {
        long size = Files.size(f), mod = Files.getLastModifiedTime(f).toMillis();
        if (System.currentTimeMillis() - mod >= cfg.settleSeconds * 1000L) return true;
        try { Thread.sleep(cfg.settleSeconds * 1000L); } catch (InterruptedException e) { Thread.currentThread().interrupt(); return false; }
        return Files.size(f) == size && Files.getLastModifiedTime(f).toMillis() == mod;
    }

    /** Uploads queued files oldest first; stops at the first network failure (and tries again next time). */
    int flushQueue() throws IOException {
        List<Path> files = new ArrayList<>();
        try (DirectoryStream<Path> ds = Files.newDirectoryStream(queueDir, "*.q")) { ds.forEach(files::add); }
        files.sort(Comparator.comparing(Path::getFileName));
        int n = 0;
        long now = System.currentTimeMillis();
        long retryAt = Long.parseLong(state.getProperty("retryAt", "0"));
        if (now < retryAt) return 0;
        for (Path q : files) {
            String csv = Files.readString(q, StandardCharsets.UTF_8);
            String name = q.getFileName().toString().replaceFirst("^\\d+-", "").replaceFirst("\\.q$", "");
            Uploader.Result r = uploader.upload(csv, cfg.full, name, sha256(csv.getBytes(StandardCharsets.UTF_8)));
            if (r.ok()) {
                Files.delete(q);
                state.setProperty("failures", "0"); state.remove("retryAt");
                log("uploaded " + name + ": " + r.body());
                n++;
            } else if (r.permanent()) {      // e.g. a bad file: keep it aside for the shop to look at, don't block the queue
                Files.move(q, q.resolveSibling(q.getFileName() + ".rejected"), StandardCopyOption.REPLACE_EXISTING);
                log("rejected " + name + " (HTTP " + r.status() + "): " + r.body());
            } else {
                int fails = Integer.parseInt(state.getProperty("failures", "0")) + 1;
                long wait = backoffSeconds(fails);
                state.setProperty("failures", String.valueOf(fails));
                state.setProperty("retryAt", String.valueOf(now + wait * 1000));
                log("offline or server busy (" + r.status() + "), retry in " + wait + " s");
                break;
            }
        }
        saveState();
        return n;
    }

    /** 30 s, 1 min, 2 min ... capped at 30 minutes. */
    static long backoffSeconds(int failures) { return Math.min(1800, 30L << Math.min(6, Math.max(0, failures - 1))); }

    /**
     * Billing software writes CSV in UTF-8, UTF-16 (Tally) or Windows-1252 (older Marg/Excel). Detect and convert,
     * so names like "Paracetamol 500mg" or "Dr. Reddy's" arrive unchanged.
     */
    static String readAsUtf8(Path f) throws IOException {
        byte[] b = Files.readAllBytes(f);
        if (b.length >= 2 && (b[0] & 0xFF) == 0xFF && (b[1] & 0xFF) == 0xFE) return new String(b, 2, b.length - 2, StandardCharsets.UTF_16LE);
        if (b.length >= 2 && (b[0] & 0xFF) == 0xFE && (b[1] & 0xFF) == 0xFF) return new String(b, 2, b.length - 2, StandardCharsets.UTF_16BE);
        int off = b.length >= 3 && (b[0] & 0xFF) == 0xEF && (b[1] & 0xFF) == 0xBB && (b[2] & 0xFF) == 0xBF ? 3 : 0;
        CharsetDecoder utf8 = StandardCharsets.UTF_8.newDecoder().onMalformedInput(CodingErrorAction.REPORT).onUnmappableCharacter(CodingErrorAction.REPORT);
        try { return utf8.decode(java.nio.ByteBuffer.wrap(b, off, b.length - off)).toString(); }
        catch (java.nio.charset.CharacterCodingException e) { return new String(b, Charset.forName("windows-1252")); }
    }

    static String sha256(byte[] data) {
        try {
            byte[] d = MessageDigest.getInstance("SHA-256").digest(data);
            StringBuilder sb = new StringBuilder();
            for (byte x : d) sb.append(String.format("%02x", x));
            return sb.toString();
        } catch (Exception e) { throw new IllegalStateException(e); }
    }

    private void saveState() throws IOException {
        Path tmp = stateFile.resolveSibling("state.tmp");
        try (var w = Files.newBufferedWriter(tmp)) { state.store(w, "AushadhBox sync agent state"); }
        Files.move(tmp, stateFile, StandardCopyOption.REPLACE_EXISTING, StandardCopyOption.ATOMIC_MOVE);
    }

    void log(String msg) {
        String line = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss")) + "  " + msg;
        System.out.println(line);
        try {
            Path lf = cfg.dataDir.resolve("sync.log");
            if (Files.exists(lf) && Files.size(lf) > 2_000_000) Files.move(lf, cfg.dataDir.resolve("sync.log.1"), StandardCopyOption.REPLACE_EXISTING);
            Files.writeString(lf, line + System.lineSeparator(), StandardCharsets.UTF_8, StandardOpenOption.CREATE, StandardOpenOption.APPEND);
        } catch (IOException ignored) { /* logging must never stop the sync */ }
    }

    /** Small holder so the comparator above compiles without a checked exception. */
    static final class FileTime0 { static final java.nio.file.attribute.FileTime ZERO = java.nio.file.attribute.FileTime.fromMillis(0); }

    /** Settings from sync.properties (see sync.properties.example). */
    public static final class Config {
        final String server, token, pattern;
        final Path folder, dataDir;
        final boolean full;
        final int intervalSeconds, settleSeconds;

        Config(Properties p, Path base) {
            server = req(p, "server").replaceAll("/+$", "");
            token = req(p, "token");
            if (!token.matches("abs_[a-f0-9]{48}")) throw new IllegalArgumentException("token must look like abs_ followed by 48 letters/digits: copy it from the portal");
            if (!server.startsWith("https://") && !server.startsWith("http://127.0.0.1") && !server.startsWith("http://localhost"))
                throw new IllegalArgumentException("server must use https:// (the token is a secret)");
            folder = Paths.get(req(p, "folder"));
            pattern = p.getProperty("pattern", "*.csv");
            full = Boolean.parseBoolean(p.getProperty("full", "true"));
            intervalSeconds = Math.max(60, Integer.parseInt(p.getProperty("intervalSeconds", "300")));
            settleSeconds = Math.max(1, Integer.parseInt(p.getProperty("settleSeconds", "10")));
            dataDir = base.resolve(p.getProperty("dataDir", "aushadhbox-sync-data"));
        }

        static String req(Properties p, String k) {
            String v = p.getProperty(k, "").trim();
            if (v.isEmpty()) throw new IllegalArgumentException("missing setting: " + k);
            return v;
        }

        public static Config load(Path file) throws IOException {
            Properties p = new Properties();
            try (InputStream in = Files.newInputStream(file)) { p.load(in); }
            Path base = file.toAbsolutePath().getParent();
            return new Config(p, base);
        }
    }

    /** Sends a CSV to sync.php. An interface, so tests can use a fake server. */
    public interface Uploader {
        record Result(boolean ok, boolean permanent, int status, String body) {}
        Result upload(String csv, boolean full, String fileName, String sha256);
    }

    /** The real uploader: HTTPS POST with the token, JSON body {csv, full, file, sha256}. */
    public static final class HttpUploader implements Uploader {
        private final HttpClient http = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(20)).build();
        private final Config cfg;
        public HttpUploader(Config cfg) { this.cfg = cfg; }

        @Override public Result upload(String csv, boolean full, String fileName, String sha) {
            String json = "{\"csv\":" + Json.str(csv) + ",\"full\":" + full + ",\"file\":" + Json.str(fileName) + ",\"sha256\":" + Json.str(sha) + "}";
            return post("stock", json);
        }

        public Result ping() { return post("ping", "{}"); }

        private Result post(String action, String json) {
            try {
                HttpRequest req = HttpRequest.newBuilder(URI.create(cfg.server + "/sync.php?action=" + action))
                        .timeout(Duration.ofSeconds(120))
                        .header("Content-Type", "application/json")
                        .header("Authorization", "Bearer " + cfg.token)
                        .header("User-Agent", "AushadhBoxSync/" + VERSION)
                        .POST(HttpRequest.BodyPublishers.ofString(json, StandardCharsets.UTF_8)).build();
                HttpResponse<String> r = http.send(req, HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
                int s = r.statusCode();
                boolean ok = s == 200;
                boolean permanent = s == 400 || s == 413 || s == 422 && !r.body().contains("corrupt");
                if (s == 401) permanent = false;   // a revoked token: keep the files, the shop will paste a new token
                return new Result(ok, permanent, s, r.body().length() > 300 ? r.body().substring(0, 300) : r.body());
            } catch (IOException e) {
                return new Result(false, false, 0, e.getClass().getSimpleName() + ": " + e.getMessage());
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                return new Result(false, false, 0, "interrupted");
            }
        }
    }

    /** Minimal JSON string escaping (the only JSON the agent writes). */
    static final class Json {
        static String str(String s) {
            StringBuilder sb = new StringBuilder(s.length() + 16).append('"');
            for (int i = 0; i < s.length(); i++) {
                char c = s.charAt(i);
                switch (c) {
                    case '"' -> sb.append("\\\"");
                    case '\\' -> sb.append("\\\\");
                    case '\n' -> sb.append("\\n");
                    case '\r' -> sb.append("\\r");
                    case '\t' -> sb.append("\\t");
                    default -> { if (c < 0x20) sb.append(String.format("\\u%04x", (int) c)); else sb.append(c); }
                }
            }
            return sb.append('"').toString();
        }
    }

    public static void main(String[] args) throws Exception {
        if (args.length < 1) {
            System.err.println("AushadhBox sync agent " + VERSION + "\nUsage: java -jar aushadhbox-sync.jar sync.properties [--once]");
            System.exit(2);
        }
        Config cfg = Config.load(Paths.get(args[0]));
        HttpUploader up = new HttpUploader(cfg);
        SyncAgent agent = new SyncAgent(cfg, up);
        Uploader.Result ping = up.ping();
        agent.log("AushadhBox sync " + VERSION + " watching " + cfg.folder + " (" + cfg.pattern + ") → " + cfg.server + "  server says: " + (ping.ok() ? ping.body() : "not reachable yet (" + ping.status() + ")"));
        if (ping.status() == 401) agent.log("The token was refused. Make a new one in the portal: Stock → Billing software sync.");
        boolean once = Arrays.asList(args).contains("--once");
        do {
            try { agent.runOnce(); } catch (Exception e) { agent.log("error: " + e); }
            if (!once) Thread.sleep(cfg.intervalSeconds * 1000L);
        } while (!once);
    }
}
