package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	sshserver "github.com/gliderlabs/ssh"
	gossh "golang.org/x/crypto/ssh"
)

type config struct {
	listen        string
	apiURL        string
	websocketURL  string
	internalToken string
	hostKeyFile   string
	user          string
}

type apiClient struct {
	baseURL string
	token   string
	http    *http.Client
}

type apiResponse struct {
	Code int             `json:"code"`
	Msg  string          `json:"msg"`
	Data json.RawMessage `json:"data"`
}

type terminalTarget struct {
	OrgID       int    `json:"org_id"`
	ClusterID   int    `json:"cluster_id"`
	NodeID      string `json:"node_id"`
	ContainerID string `json:"container_id"`
	ProjectID   int    `json:"project_id,omitempty"`
}

type terminalTicket struct {
	Ticket string `json:"ticket"`
}

type terminalSession struct {
	Transport string         `json:"transport"`
	WebSocket terminalTicket `json:"websocket"`
}

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatal(err)
	}
	if err := ensureHostKey(cfg.hostKeyFile); err != nil {
		log.Fatalf("prepare SSH host key: %v", err)
	}
	client := &apiClient{
		baseURL: strings.TrimRight(cfg.apiURL, "/"),
		token:   cfg.internalToken,
		http:    &http.Client{Timeout: 10 * time.Second},
	}

	server := &sshserver.Server{
		Addr: cfg.listen,
		Handler: func(session sshserver.Session) {
			if err := relaySession(cfg, client, session); err != nil {
				fmt.Fprintf(session.Stderr(), "连接容器终端失败：%v\r\n", err)
				_ = session.Exit(1)
			}
		},
		PublicKeyHandler: func(ctx sshserver.Context, key sshserver.PublicKey) bool {
			if ctx.User() != cfg.user {
				return false
			}
			uid, err := client.authenticate(ctx, gossh.FingerprintSHA256(key))
			if err != nil {
				log.Printf("SSH key rejected from %s: %v", ctx.RemoteAddr(), err)
				return false
			}
			ctx.SetValue("uid", uid)
			return true
		},
		IdleTimeout: 30 * time.Minute,
		MaxTimeout:  24 * time.Hour,
	}
	server.SetOption(sshserver.HostKeyFile(cfg.hostKeyFile))
	log.Printf("Galaxy SSH relay listening on %s", cfg.listen)
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

func loadConfig() (config, error) {
	cfg := config{
		listen:        env("SSH_RELAY_LISTEN", ":9522"),
		apiURL:        env("SSH_RELAY_API_URL", "http://127.0.0.1:9501"),
		websocketURL:  env("SSH_RELAY_WEBSOCKET_URL", "ws://127.0.0.1:9501/cluster/swarm/terminal"),
		internalToken: os.Getenv("SSH_RELAY_INTERNAL_TOKEN"),
		hostKeyFile:   env("SSH_RELAY_HOST_KEY_FILE", "runtime/ssh/host_key"),
		user:          env("SSH_RELAY_USER", "galaxy"),
	}
	if cfg.internalToken == "" {
		return config{}, errors.New("SSH_RELAY_INTERNAL_TOKEN is required")
	}
	if _, err := url.ParseRequestURI(cfg.apiURL); err != nil {
		return config{}, fmt.Errorf("invalid SSH_RELAY_API_URL: %w", err)
	}
	wsURL, err := url.Parse(cfg.websocketURL)
	if err != nil || (wsURL.Scheme != "ws" && wsURL.Scheme != "wss") || wsURL.Host == "" {
		return config{}, errors.New("SSH_RELAY_WEBSOCKET_URL must be an absolute ws:// or wss:// URL")
	}
	return cfg, nil
}

func env(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}

func ensureHostKey(path string) error {
	if info, err := os.Stat(path); err == nil {
		if info.Mode().Perm()&0077 != 0 {
			return fmt.Errorf("%s must not be accessible by group or others", path)
		}
		return nil
	} else if !os.IsNotExist(err) {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	_, privateKey, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return err
	}
	encoded, err := x509.MarshalPKCS8PrivateKey(privateKey)
	if err != nil {
		return err
	}
	return os.WriteFile(path, pem.EncodeToMemory(&pem.Block{Type: "PRIVATE KEY", Bytes: encoded}), 0600)
}

func (c *apiClient) authenticate(ctx context.Context, fingerprint string) (int, error) {
	var data struct {
		UID int `json:"uid"`
	}
	if err := c.post(ctx, "/internal/ssh/authenticate", map[string]any{"fingerprint": fingerprint}, &data); err != nil {
		return 0, err
	}
	if data.UID < 1 {
		return 0, errors.New("API returned an invalid user id")
	}
	return data.UID, nil
}

func (c *apiClient) terminalSession(ctx context.Context, uid int, target terminalTarget) (terminalSession, error) {
	payload := map[string]any{
		"uid": uid, "org_id": target.OrgID, "cluster_id": target.ClusterID,
		"node_id": target.NodeID, "container_id": target.ContainerID,
	}
	if target.ProjectID > 0 {
		payload["project_id"] = target.ProjectID
	}
	var descriptor terminalSession
	err := c.post(ctx, "/internal/ssh/terminal-session", payload, &descriptor)
	if err == nil && descriptor.Transport != "websocket" {
		err = fmt.Errorf("API returned unsupported terminal transport %q; Galaxy Agent WebSocket is required", descriptor.Transport)
	}
	return descriptor, err
}

func (c *apiClient) post(ctx context.Context, path string, payload any, output any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	request.Header.Set("Authorization", "Bearer "+c.token)
	request.Header.Set("Content-Type", "application/json")
	response, err := c.http.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	limited := io.LimitReader(response.Body, 1<<20)
	var envelope apiResponse
	if err := json.NewDecoder(limited).Decode(&envelope); err != nil {
		return fmt.Errorf("API returned HTTP %d with invalid JSON", response.StatusCode)
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 || envelope.Code != 0 {
		if envelope.Msg == "" {
			envelope.Msg = response.Status
		}
		return errors.New(envelope.Msg)
	}
	if err := json.Unmarshal(envelope.Data, output); err != nil {
		return fmt.Errorf("decode API response: %w", err)
	}
	return nil
}

func parseTarget(command []string) (terminalTarget, error) {
	if len(command) == 0 || command[0] != "exec" {
		return terminalTarget{}, errors.New("用法: exec --org ORG --cluster CLUSTER --node NODE --container CONTAINER [--project PROJECT]")
	}
	flags := flag.NewFlagSet("exec", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	var target terminalTarget
	flags.IntVar(&target.OrgID, "org", 0, "organization id")
	flags.IntVar(&target.ClusterID, "cluster", 0, "cluster id")
	flags.StringVar(&target.NodeID, "node", "", "Docker Swarm node id")
	flags.StringVar(&target.ContainerID, "container", "", "container id")
	flags.IntVar(&target.ProjectID, "project", 0, "project id")
	if err := flags.Parse(command[1:]); err != nil {
		return terminalTarget{}, err
	}
	if target.OrgID < 1 || target.ClusterID < 1 || target.NodeID == "" || target.ContainerID == "" {
		return terminalTarget{}, errors.New("--org、--cluster、--node 和 --container 都是必填参数")
	}
	if len(target.NodeID) > 128 ||
		!((target.NodeID[0] >= '0' && target.NodeID[0] <= '9') ||
			(target.NodeID[0] >= 'a' && target.NodeID[0] <= 'z') ||
			(target.NodeID[0] >= 'A' && target.NodeID[0] <= 'Z')) ||
		strings.IndexFunc(target.NodeID, func(r rune) bool {
			return !((r >= '0' && r <= '9') ||
				(r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') ||
				r == '.' || r == '_' || r == ':' || r == '-')
		}) >= 0 {
		return terminalTarget{}, errors.New("节点 ID 格式不合法")
	}
	if len(target.ContainerID) > 64 || strings.IndexFunc(target.ContainerID, func(r rune) bool {
		return !(r >= '0' && r <= '9') && !(r >= 'a' && r <= 'f') && !(r >= 'A' && r <= 'F')
	}) >= 0 {
		return terminalTarget{}, errors.New("容器 ID 格式不合法")
	}
	if flags.NArg() != 0 {
		return terminalTarget{}, errors.New("不支持额外的远程命令")
	}
	return target, nil
}

func relaySession(cfg config, api *apiClient, session sshserver.Session) error {
	target, err := parseTarget(session.Command())
	if err != nil {
		return err
	}
	uid, ok := session.Context().Value("uid").(int)
	if !ok || uid < 1 {
		return errors.New("SSH identity is missing")
	}
	pty, windowChanges, ok := session.Pty()
	if !ok {
		return errors.New("需要分配 PTY，请使用 ssh -t")
	}
	pty.Window.Width = clamp(pty.Window.Width, 20, 500)
	pty.Window.Height = clamp(pty.Window.Height, 5, 300)
	descriptor, err := api.terminalSession(session.Context(), uid, target)
	if err != nil {
		return err
	}
	transport, err := openWebSocketTerminal(
		session.Context(), cfg.websocketURL, descriptor.WebSocket.Ticket,
		pty.Window.Width, pty.Window.Height,
	)
	if err != nil {
		return err
	}
	defer transport.Close()
	return bridgeTerminal(session, pty.Window, windowChanges, transport)
}
