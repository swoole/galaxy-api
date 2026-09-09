package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gorilla/websocket"
)

func TestWebSocketTerminalFallback(t *testing.T) {
	upgrader := websocket.Upgrader{}
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Query().Get("ticket") != "ticket-1" {
			http.Error(writer, "missing ticket", http.StatusUnauthorized)
			return
		}
		if request.URL.Query().Get("cols") != "132" || request.URL.Query().Get("rows") != "43" {
			http.Error(writer, "missing terminal size", http.StatusBadRequest)
			return
		}
		connection, err := upgrader.Upgrade(writer, request, nil)
		if err != nil {
			return
		}
		defer connection.Close()
		for {
			messageType, data, err := connection.ReadMessage()
			if err != nil {
				return
			}
			if messageType == websocket.BinaryMessage {
				_ = connection.WriteMessage(websocket.BinaryMessage, data)
			}
		}
	}))
	defer server.Close()

	transport, err := openWebSocketTerminal(
		context.Background(), "ws"+strings.TrimPrefix(server.URL, "http"), "ticket-1", 132, 43,
	)
	if err != nil {
		t.Fatal(err)
	}
	defer transport.Close()
	if err := transport.Resize(120, 40); err != nil {
		t.Fatal(err)
	}
	if err := transport.Write([]byte("ping")); err != nil {
		t.Fatal(err)
	}
	data, err := transport.Read()
	if err != nil {
		t.Fatal(err)
	}
	if string(data) != "ping" {
		t.Fatalf("unexpected terminal data %q", data)
	}
}
