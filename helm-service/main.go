package main

import (
	"archive/tar"
	"compress/gzip"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"helm.sh/helm/v3/pkg/action"
	"helm.sh/helm/v3/pkg/chart/loader"
	"helm.sh/helm/v3/pkg/cli"
	helrelease "helm.sh/helm/v3/pkg/release"
	"helm.sh/helm/v3/pkg/storage/driver"
)

const maxRequestBody = 4 << 20

type config struct {
	listen string
	token  string
}

type server struct {
	token string
	slots chan struct{}
}

type releaseRequest struct {
	Kubeconfig string         `json:"kubeconfig"`
	Namespace  string         `json:"namespace"`
	Name       string         `json:"name"`
	Chart      string         `json:"chart"`
	Repository string         `json:"repository"`
	Version    string         `json:"version"`
	ArchiveURL string         `json:"archive_url"`
	Subpath    string         `json:"archive_subpath"`
	Values     map[string]any `json:"values"`
	Wait       bool           `json:"wait"`
	Timeout    int            `json:"timeout_seconds"`
}

type releaseLookup struct {
	Kubeconfig string `json:"kubeconfig"`
	Namespace  string `json:"namespace"`
	Name       string `json:"name"`
	Timeout    int    `json:"timeout_seconds"`
}

type chartInspectRequest struct {
	Chart      string `json:"chart"`
	Repository string `json:"repository"`
	Version    string `json:"version"`
	ArchiveURL string `json:"archive_url"`
	Subpath    string `json:"archive_subpath"`
}

type releaseResult struct {
	Name      string `json:"name"`
	Namespace string `json:"namespace"`
	Revision  int    `json:"revision"`
	Status    string `json:"status"`
	Chart     string `json:"chart,omitempty"`
	UpdatedAt string `json:"updated_at,omitempty"`
}

type responseEnvelope struct {
	OK    bool   `json:"ok"`
	Data  any    `json:"data,omitempty"`
	Error string `json:"error,omitempty"`
}

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatal(err)
	}
	s := &server{token: cfg.token, slots: make(chan struct{}, 2)}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", s.health)
	mux.HandleFunc("POST /v1/charts/inspect", s.authorize(s.inspectChart))
	mux.HandleFunc("POST /v1/releases/apply", s.authorize(s.apply))
	mux.HandleFunc("POST /v1/releases/status", s.authorize(s.status))
	mux.HandleFunc("POST /v1/releases/uninstall", s.authorize(s.uninstall))

	httpServer := &http.Server{
		Addr:              cfg.listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      35 * time.Minute,
		IdleTimeout:       30 * time.Second,
	}
	log.Printf("Galaxy Helm service listening on %s", cfg.listen)
	if err := httpServer.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

func (s *server) inspectChart(w http.ResponseWriter, r *http.Request) {
	var source chartInspectRequest
	if err := decodeRequest(r, &source); err != nil {
		writeJSON(w, http.StatusBadRequest, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	request := releaseRequest{
		Kubeconfig: "not-used",
		Namespace:  "default",
		Name:       "inspect",
		Chart:      source.Chart,
		Repository: source.Repository,
		Version:    source.Version,
		ArchiveURL: source.ArchiveURL,
		Subpath:    source.Subpath,
	}
	if err := validateChartSource(request); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	settings := cli.New()
	options := action.ChartPathOptions{RepoURL: source.Repository, Version: source.Version}
	path, cleanup, err := locateChart(r, request, settings, options)
	if err != nil {
		writeHelmError(w, fmt.Errorf("locate chart: %w", err))
		return
	}
	defer cleanup()
	chart, err := loader.Load(path)
	if err != nil {
		writeHelmError(w, fmt.Errorf("load chart: %w", err))
		return
	}
	dependencies := make([]map[string]string, 0, len(chart.Metadata.Dependencies))
	for _, dependency := range chart.Metadata.Dependencies {
		dependencies = append(dependencies, map[string]string{
			"name": dependency.Name, "version": dependency.Version, "repository": dependency.Repository,
		})
	}
	writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: map[string]any{
		"name": chart.Metadata.Name, "version": chart.Metadata.Version,
		"app_version": chart.Metadata.AppVersion, "dependencies": dependencies,
	}})
}

func loadConfig() (config, error) {
	cfg := config{
		listen: env("HELM_SERVICE_LISTEN", "127.0.0.1:9530"),
		token:  strings.TrimSpace(os.Getenv("HELM_SERVICE_INTERNAL_TOKEN")),
	}
	if cfg.token == "" {
		return config{}, errors.New("HELM_SERVICE_INTERNAL_TOKEN is required")
	}
	host, _, err := net.SplitHostPort(cfg.listen)
	if err != nil {
		return config{}, fmt.Errorf("invalid HELM_SERVICE_LISTEN: %w", err)
	}
	ip := net.ParseIP(strings.Trim(host, "[]"))
	if host != "localhost" && (ip == nil || !ip.IsLoopback()) {
		return config{}, errors.New("HELM_SERVICE_LISTEN must use a loopback address")
	}
	return cfg, nil
}

func env(name, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(name)); value != "" {
		return value
	}
	return fallback
}

func (s *server) health(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: map[string]string{"service": "galaxy-helm"}})
}

func (s *server) authorize(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		provided := strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer ")
		if len(provided) != len(s.token) || subtle.ConstantTimeCompare([]byte(provided), []byte(s.token)) != 1 {
			writeJSON(w, http.StatusUnauthorized, responseEnvelope{OK: false, Error: "unauthorized"})
			return
		}
		select {
		case s.slots <- struct{}{}:
			defer func() { <-s.slots }()
		case <-r.Context().Done():
			writeJSON(w, http.StatusRequestTimeout, responseEnvelope{OK: false, Error: "request cancelled"})
			return
		}
		next(w, r)
	}
}

func (s *server) apply(w http.ResponseWriter, r *http.Request) {
	var request releaseRequest
	if err := decodeRequest(r, &request); err != nil {
		writeJSON(w, http.StatusBadRequest, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	if err := validateApply(request); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	settings, cleanup, err := requestSettings(request.Kubeconfig, request.Namespace)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	defer cleanup()

	actionConfig := new(action.Configuration)
	if err := actionConfig.Init(settings.RESTClientGetter(), request.Namespace, "secret", helmLog); err != nil {
		writeHelmError(w, err)
		return
	}
	chartPathOptions := action.ChartPathOptions{
		RepoURL: request.Repository,
		Version: request.Version,
	}
	chartPath, chartCleanup, err := locateChart(r, request, settings, chartPathOptions)
	if err != nil {
		writeHelmError(w, fmt.Errorf("locate chart: %w", err))
		return
	}
	defer chartCleanup()
	chart, err := loader.Load(chartPath)
	if err != nil {
		writeHelmError(w, fmt.Errorf("load chart: %w", err))
		return
	}

	timeout := normalizedTimeout(request.Timeout)
	history := action.NewHistory(actionConfig)
	history.Max = 1
	releases, historyErr := history.Run(request.Name)
	if historyErr != nil && !errors.Is(historyErr, driver.ErrReleaseNotFound) {
		writeHelmError(w, fmt.Errorf("read release history: %w", historyErr))
		return
	}

	if historyErr == nil && len(releases) > 0 {
		client := action.NewUpgrade(actionConfig)
		client.Namespace = request.Namespace
		client.ChartPathOptions = chartPathOptions
		client.Wait = request.Wait
		client.Timeout = timeout
		client.Atomic = request.Wait
		client.CleanupOnFail = true
		release, err := client.RunWithContext(r.Context(), request.Name, chart, request.Values)
		if err != nil {
			writeHelmError(w, fmt.Errorf("upgrade release: %w", err))
			return
		}
		writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: result(release)})
		return
	}

	client := action.NewInstall(actionConfig)
	client.ReleaseName = request.Name
	client.Namespace = request.Namespace
	client.CreateNamespace = true
	client.ChartPathOptions = chartPathOptions
	client.Wait = request.Wait
	client.Timeout = timeout
	client.Atomic = request.Wait
	release, err := client.RunWithContext(r.Context(), chart, request.Values)
	if err != nil {
		writeHelmError(w, fmt.Errorf("install release: %w", err))
		return
	}
	writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: result(release)})
}

func (s *server) status(w http.ResponseWriter, r *http.Request) {
	var request releaseLookup
	if err := decodeRequest(r, &request); err != nil {
		writeJSON(w, http.StatusBadRequest, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	if err := validateLookup(request); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	settings, cleanup, err := requestSettings(request.Kubeconfig, request.Namespace)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	defer cleanup()
	actionConfig := new(action.Configuration)
	if err := actionConfig.Init(settings.RESTClientGetter(), request.Namespace, "secret", helmLog); err != nil {
		writeHelmError(w, err)
		return
	}
	release, err := action.NewStatus(actionConfig).Run(request.Name)
	if err != nil {
		writeHelmError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: result(release)})
}

func (s *server) uninstall(w http.ResponseWriter, r *http.Request) {
	var request releaseLookup
	if err := decodeRequest(r, &request); err != nil {
		writeJSON(w, http.StatusBadRequest, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	if err := validateLookup(request); err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	settings, cleanup, err := requestSettings(request.Kubeconfig, request.Namespace)
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, responseEnvelope{OK: false, Error: err.Error()})
		return
	}
	defer cleanup()
	actionConfig := new(action.Configuration)
	if err := actionConfig.Init(settings.RESTClientGetter(), request.Namespace, "secret", helmLog); err != nil {
		writeHelmError(w, err)
		return
	}
	client := action.NewUninstall(actionConfig)
	client.Wait = true
	client.Timeout = normalizedTimeout(request.Timeout)
	release, err := client.Run(request.Name)
	if err != nil {
		writeHelmError(w, err)
		return
	}
	writeJSON(w, http.StatusOK, responseEnvelope{OK: true, Data: map[string]any{
		"name": request.Name, "namespace": request.Namespace, "info": release.Info,
	}})
}

func requestSettings(kubeconfig, namespace string) (*cli.EnvSettings, func(), error) {
	if strings.TrimSpace(kubeconfig) == "" {
		return nil, func() {}, errors.New("kubeconfig is required")
	}
	file, err := os.CreateTemp("", "galaxy-helm-kubeconfig-*")
	if err != nil {
		return nil, func() {}, fmt.Errorf("create kubeconfig: %w", err)
	}
	path := file.Name()
	cleanup := sync.OnceFunc(func() {
		_ = file.Close()
		_ = os.Remove(path)
	})
	if err := file.Chmod(0600); err != nil {
		cleanup()
		return nil, func() {}, fmt.Errorf("protect kubeconfig: %w", err)
	}
	if _, err := io.WriteString(file, kubeconfig); err != nil {
		cleanup()
		return nil, func() {}, fmt.Errorf("write kubeconfig: %w", err)
	}
	if err := file.Close(); err != nil {
		cleanup()
		return nil, func() {}, fmt.Errorf("close kubeconfig: %w", err)
	}
	settings := cli.New()
	settings.KubeConfig = path
	settings.SetNamespace(namespace)
	return settings, cleanup, nil
}

func validateApply(request releaseRequest) error {
	if err := validateLookup(releaseLookup{
		Kubeconfig: request.Kubeconfig,
		Namespace:  request.Namespace,
		Name:       request.Name,
	}); err != nil {
		return err
	}
	return validateChartSource(request)
}

func validateChartSource(request releaseRequest) error {
	if strings.TrimSpace(request.Chart) == "" {
		return errors.New("chart is required")
	}
	if request.Repository != "" {
		parsed, err := url.Parse(request.Repository)
		if err != nil || parsed.Scheme != "https" || parsed.Host == "" {
			return errors.New("repository must be an absolute HTTPS URL")
		}
	}
	if request.ArchiveURL != "" {
		parsed, err := url.Parse(request.ArchiveURL)
		if err != nil || parsed.Scheme != "https" || parsed.Host == "" {
			return errors.New("archive_url must be an absolute HTTPS URL")
		}
		if request.Subpath == "" || filepath.IsAbs(request.Subpath) || strings.Contains(request.Subpath, "..") {
			return errors.New("archive_subpath must be a safe relative path")
		}
	}
	return nil
}

func locateChart(
	r *http.Request,
	request releaseRequest,
	settings *cli.EnvSettings,
	options action.ChartPathOptions,
) (string, func(), error) {
	if request.ArchiveURL == "" {
		path, err := options.LocateChart(request.Chart, settings)
		return path, func() {}, err
	}
	tempDirectory, err := os.MkdirTemp("", "galaxy-helm-chart-*")
	if err != nil {
		return "", func() {}, err
	}
	cleanup := sync.OnceFunc(func() { _ = os.RemoveAll(tempDirectory) })
	client := &http.Client{
		Timeout: 2 * time.Minute,
		CheckRedirect: func(redirect *http.Request, previous []*http.Request) error {
			if len(previous) >= 5 || redirect.URL.Scheme != "https" {
				return errors.New("unsafe chart archive redirect")
			}
			return nil
		},
	}
	downloadRequest, err := http.NewRequestWithContext(r.Context(), http.MethodGet, request.ArchiveURL, nil)
	if err != nil {
		cleanup()
		return "", func() {}, err
	}
	downloadRequest.Header.Set("User-Agent", "Galaxy-Helm-Service/1.0")
	response, err := client.Do(downloadRequest)
	if err != nil {
		cleanup()
		return "", func() {}, err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		cleanup()
		return "", func() {}, fmt.Errorf("chart archive returned HTTP %d", response.StatusCode)
	}
	const maxArchive = 64 << 20
	compressed := io.LimitReader(response.Body, maxArchive+1)
	gzipReader, err := gzip.NewReader(compressed)
	if err != nil {
		cleanup()
		return "", func() {}, fmt.Errorf("chart archive is not gzip: %w", err)
	}
	defer gzipReader.Close()
	tarReader := tar.NewReader(gzipReader)
	extractedBytes := int64(0)
	const maxExtracted = 256 << 20
	for {
		header, err := tarReader.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			cleanup()
			return "", func() {}, fmt.Errorf("read chart archive: %w", err)
		}
		cleanName := filepath.Clean(header.Name)
		if cleanName == "." || filepath.IsAbs(cleanName) || strings.HasPrefix(cleanName, ".."+string(filepath.Separator)) {
			cleanup()
			return "", func() {}, errors.New("chart archive contains an unsafe path")
		}
		target := filepath.Join(tempDirectory, cleanName)
		if !strings.HasPrefix(target, tempDirectory+string(filepath.Separator)) {
			cleanup()
			return "", func() {}, errors.New("chart archive escaped the temporary directory")
		}
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0750); err != nil {
				cleanup()
				return "", func() {}, err
			}
		case tar.TypeReg:
			extractedBytes += header.Size
			if header.Size < 0 || extractedBytes > maxExtracted {
				cleanup()
				return "", func() {}, errors.New("chart archive is too large")
			}
			if err := os.MkdirAll(filepath.Dir(target), 0750); err != nil {
				cleanup()
				return "", func() {}, err
			}
			file, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
			if err != nil {
				cleanup()
				return "", func() {}, err
			}
			if _, err := io.CopyN(file, tarReader, header.Size); err != nil {
				_ = file.Close()
				cleanup()
				return "", func() {}, err
			}
			if err := file.Close(); err != nil {
				cleanup()
				return "", func() {}, err
			}
		}
	}
	matches, err := filepath.Glob(filepath.Join(tempDirectory, "*", filepath.FromSlash(request.Subpath)))
	if err != nil || len(matches) != 1 {
		cleanup()
		return "", func() {}, errors.New("chart subpath was not found exactly once")
	}
	if _, err := os.Stat(filepath.Join(matches[0], "Chart.yaml")); err != nil {
		cleanup()
		return "", func() {}, errors.New("chart subpath does not contain Chart.yaml")
	}
	return matches[0], cleanup, nil
}

func validateLookup(request releaseLookup) error {
	if len(request.Kubeconfig) == 0 || len(request.Kubeconfig) > 2<<20 {
		return errors.New("kubeconfig is required and must not exceed 2 MiB")
	}
	if !dnsLabel(request.Namespace) {
		return errors.New("invalid namespace")
	}
	if !dnsLabel(request.Name) {
		return errors.New("invalid release name")
	}
	return nil
}

func dnsLabel(value string) bool {
	if len(value) < 1 || len(value) > 63 {
		return false
	}
	for index, character := range value {
		if (character >= 'a' && character <= 'z') || (character >= '0' && character <= '9') {
			continue
		}
		if character == '-' && index > 0 && index < len(value)-1 {
			continue
		}
		return false
	}
	return true
}

func normalizedTimeout(seconds int) time.Duration {
	if seconds < 60 {
		seconds = 900
	}
	if seconds > 1800 {
		seconds = 1800
	}
	return time.Duration(seconds) * time.Second
}

func decodeRequest(r *http.Request, target any) error {
	defer r.Body.Close()
	decoder := json.NewDecoder(http.MaxBytesReader(nil, r.Body, maxRequestBody))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return fmt.Errorf("invalid request: %w", err)
	}
	return nil
}

func result(item *helrelease.Release) releaseResult {
	output := releaseResult{
		Name:      item.Name,
		Namespace: item.Namespace,
		Revision:  item.Version,
	}
	if item.Info != nil {
		output.Status = item.Info.Status.String()
		output.UpdatedAt = item.Info.LastDeployed.String()
	}
	if item.Chart != nil && item.Chart.Metadata != nil {
		output.Chart = item.Chart.Metadata.Name + "-" + item.Chart.Metadata.Version
	}
	return output
}

func helmLog(format string, values ...interface{}) {
	log.Printf("helm: "+format, values...)
}

func writeHelmError(w http.ResponseWriter, err error) {
	log.Printf("Helm operation failed: %v", err)
	writeJSON(w, http.StatusBadGateway, responseEnvelope{OK: false, Error: err.Error()})
}

func writeJSON(w http.ResponseWriter, status int, payload responseEnvelope) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}
