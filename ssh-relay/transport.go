package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"strconv"
	"sync"

	sshserver "github.com/gliderlabs/ssh"
	"github.com/gorilla/websocket"
)

type terminalTransport interface {
	Read() ([]byte, error)
	Write([]byte) error
	Resize(width, height int) error
	Close() error
	NormalClose(error) bool
}

func clamp(value, minimum, maximum int) int {
	if value < minimum {
		return minimum
	}
	if value > maximum {
		return maximum
	}
	return value
}

func bridgeTerminal(
	session sshserver.Session,
	initial sshserver.Window,
	windowChanges <-chan sshserver.Window,
	transport terminalTransport,
) error {
	_ = transport.Resize(initial.Width, initial.Height)
	errCh := make(chan error, 3)
	go func() {
		for change := range windowChanges {
			if err := transport.Resize(change.Width, change.Height); err != nil {
				errCh <- err
				return
			}
		}
	}()
	go func() {
		buffer := make([]byte, 32*1024)
		for {
			n, err := session.Read(buffer)
			if n > 0 {
				if writeErr := transport.Write(buffer[:n]); writeErr != nil {
					errCh <- writeErr
					return
				}
			}
			if err != nil {
				errCh <- err
				return
			}
		}
	}()
	go func() {
		for {
			data, err := transport.Read()
			if len(data) > 0 {
				if _, writeErr := session.Write(data); writeErr != nil {
					errCh <- writeErr
					return
				}
			}
			if err != nil {
				errCh <- err
				return
			}
		}
	}()
	err := <-errCh
	if errors.Is(err, io.EOF) || transport.NormalClose(err) {
		return nil
	}
	return err
}

type webSocketTerminal struct {
	connection *websocket.Conn
	writeMu    sync.Mutex
}

func openWebSocketTerminal(ctx context.Context, endpointValue, ticket string, width, height int) (terminalTransport, error) {
	if ticket == "" {
		return nil, errors.New("API returned an empty terminal ticket")
	}
	endpoint, _ := url.Parse(endpointValue)
	query := endpoint.Query()
	query.Set("ticket", ticket)
	query.Set("cols", strconv.Itoa(width))
	query.Set("rows", strconv.Itoa(height))
	endpoint.RawQuery = query.Encode()
	connection, response, err := websocket.DefaultDialer.DialContext(ctx, endpoint.String(), nil)
	if err != nil {
		if response != nil {
			return nil, fmt.Errorf("terminal WebSocket handshake failed: HTTP %d", response.StatusCode)
		}
		return nil, fmt.Errorf("connect terminal WebSocket: %w", err)
	}
	return &webSocketTerminal{connection: connection}, nil
}

func (t *webSocketTerminal) Read() ([]byte, error) {
	_, data, err := t.connection.ReadMessage()
	return data, err
}

func (t *webSocketTerminal) Write(data []byte) error {
	return t.write(websocket.BinaryMessage, data)
}

func (t *webSocketTerminal) Resize(width, height int) error {
	data, err := json.Marshal(map[string]any{"type": "resize", "cols": width, "rows": height})
	if err != nil {
		return err
	}
	return t.write(websocket.TextMessage, data)
}

func (t *webSocketTerminal) write(messageType int, data []byte) error {
	t.writeMu.Lock()
	defer t.writeMu.Unlock()
	return t.connection.WriteMessage(messageType, data)
}

func (t *webSocketTerminal) Close() error { return t.connection.Close() }

func (t *webSocketTerminal) NormalClose(err error) bool {
	return websocket.IsCloseError(err, websocket.CloseNormalClosure, websocket.CloseGoingAway)
}
