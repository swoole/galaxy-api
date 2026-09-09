package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestParseTarget(t *testing.T) {
	target, err := parseTarget([]string{
		"exec", "--org", "29", "--cluster", "3", "--node", "worker-node_1",
		"--container", "abcdef123456", "--project", "42",
	})
	if err != nil {
		t.Fatal(err)
	}
	if target.OrgID != 29 || target.ClusterID != 3 || target.NodeID != "worker-node_1" ||
		target.ContainerID != "abcdef123456" || target.ProjectID != 42 {
		t.Fatalf("unexpected target: %#v", target)
	}
}

func TestParseTargetRejectsUnsafeInput(t *testing.T) {
	cases := [][]string{
		{},
		{"shell"},
		{"exec", "--org", "1", "--cluster", "2", "--container", "abcdef123456"},
		{"exec", "--org", "1", "--cluster", "2", "--node", "../worker", "--container", "abcdef123456"},
		{"exec", "--org", "1", "--cluster", "2", "--node", "worker-1", "--container", "abc;id"},
		{"exec", "--org", "1", "--cluster", "2", "--node", "worker-1", "--container", "abcdef123456", "sh"},
	}
	for _, args := range cases {
		if _, err := parseTarget(args); err == nil {
			t.Fatalf("expected parse error for %#v", args)
		}
	}
}

func TestTerminalSessionDescriptor(t *testing.T) {
	var payload terminalTarget
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/internal/ssh/terminal-session" {
			http.NotFound(writer, request)
			return
		}
		if request.Header.Get("Authorization") != "Bearer secret" {
			http.Error(writer, "unauthorized", http.StatusUnauthorized)
			return
		}
		if err := json.NewDecoder(request.Body).Decode(&payload); err != nil {
			http.Error(writer, err.Error(), http.StatusBadRequest)
			return
		}
		_ = json.NewEncoder(writer).Encode(map[string]any{
			"code": 0, "msg": "success", "data": map[string]any{
				"transport": "websocket",
				"websocket": map[string]any{"ticket": "ticket-1"},
			},
		})
	}))
	defer server.Close()
	client := &apiClient{baseURL: server.URL, token: "secret", http: &http.Client{Timeout: time.Second}}
	descriptor, err := client.terminalSession(context.Background(), 9, terminalTarget{
		OrgID: 1, ClusterID: 2, NodeID: "worker-1", ContainerID: "abcdef123456",
	})
	if err != nil {
		t.Fatal(err)
	}
	if descriptor.Transport != "websocket" || descriptor.WebSocket.Ticket != "ticket-1" {
		t.Fatalf("unexpected descriptor: %#v", descriptor)
	}
	if payload.NodeID != "worker-1" || payload.ContainerID != "abcdef123456" {
		t.Fatalf("unexpected API payload: %#v", payload)
	}
}

func TestTerminalSessionRejectsDockerTransport(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		_ = json.NewEncoder(writer).Encode(map[string]any{
			"code": 0, "msg": "success", "data": map[string]any{"transport": "docker"},
		})
	}))
	defer server.Close()
	client := &apiClient{baseURL: server.URL, token: "secret", http: &http.Client{Timeout: time.Second}}
	_, err := client.terminalSession(context.Background(), 9, terminalTarget{
		OrgID: 1, ClusterID: 2, NodeID: "worker-1", ContainerID: "abcdef123456",
	})
	if err == nil {
		t.Fatal("expected Docker transport to be rejected")
	}
}
