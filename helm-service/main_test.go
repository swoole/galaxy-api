package main

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestValidateChartSource(t *testing.T) {
	valid := releaseRequest{
		Chart:      "ks-core",
		ArchiveURL: "https://github.com/kubesphere/kubesphere/archive/refs/tags/v4.1.2.tar.gz",
		Subpath:    "config/ks-core",
	}
	if err := validateChartSource(valid); err != nil {
		t.Fatalf("valid chart source rejected: %v", err)
	}
	invalid := valid
	invalid.Subpath = "../../etc"
	if err := validateChartSource(invalid); err == nil {
		t.Fatal("unsafe archive subpath was accepted")
	}
	invalid = valid
	invalid.ArchiveURL = "http://example.com/chart.tgz"
	if err := validateChartSource(invalid); err == nil {
		t.Fatal("non-HTTPS archive URL was accepted")
	}
}

func TestAuthorization(t *testing.T) {
	s := &server{token: "secret-token", slots: make(chan struct{}, 1)}
	handler := s.authorize(func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, responseEnvelope{OK: true})
	})

	unauthorized := httptest.NewRecorder()
	handler(unauthorized, httptest.NewRequest(http.MethodPost, "/v1/releases/status", nil))
	if unauthorized.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401, got %d", unauthorized.Code)
	}

	authorizedRequest := httptest.NewRequest(http.MethodPost, "/v1/releases/status", nil)
	authorizedRequest.Header.Set("Authorization", "Bearer secret-token")
	authorized := httptest.NewRecorder()
	handler(authorized, authorizedRequest)
	if authorized.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", authorized.Code)
	}
}

func TestDNSLabel(t *testing.T) {
	for _, value := range []string{"ks-core", "a", "release-123"} {
		if !dnsLabel(value) {
			t.Fatalf("valid DNS label rejected: %s", value)
		}
	}
	for _, value := range []string{"", "-bad", "bad-", "UPPER", "contains.dot"} {
		if dnsLabel(value) {
			t.Fatalf("invalid DNS label accepted: %s", value)
		}
	}
}
