<?php

namespace App\Controller\Cluster;

use App\Controller\AbstractController;
use App\Services\Kubernetes\KubernetesClusterService;
use App\Services\Kubernetes\KubernetesTerminalService;
use App\Support\Functions;

final class KubernetesClusterController extends AbstractController
{
    public function __construct(
        private KubernetesClusterService $clusters,
        private KubernetesTerminalService $terminal
    ) {}

    public function index()
    {
        return $this->success([
            'clusters' => $this->clusters->list((int) Functions::getContextValue('org_id')),
        ]);
    }

    public function create()
    {
        $params = $this->validate([
            'title' => 'required|string|max:20',
            'remark' => 'nullable|string|max:2000',
            'kubeconfig' => 'required|string|max:1048576',
        ]);
        $cluster = $this->clusters->create(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            (string) $params['title'],
            (string) ($params['remark'] ?? ''),
            (string) $params['kubeconfig']
        );
        return $this->success(['cluster_id' => (int) $cluster->id]);
    }

    public function updateCredential()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'kubeconfig' => 'required|string|max:1048576',
        ]);
        return $this->success($this->clusters->updateCredential(
            (int) Functions::getContextValue('org_id'),
            (int) $params['cluster_id'],
            (string) $params['kubeconfig']
        ));
    }

    public function profile()
    {
        return $this->success($this->clusters->profile(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        ));
    }

    public function updateIngressPorts()
    {
        $params = $this->validate([
            'cluster_id' => 'required|integer|min:1',
            'ingress_http_port' => 'required|integer|min:1|max:65535',
            'ingress_https_port' => 'required|integer|min:1|max:65535',
        ]);
        return $this->success($this->clusters->updateIngressPorts(
            (int) Functions::getContextValue('org_id'),
            (int) $params['cluster_id'],
            (int) $params['ingress_http_port'],
            (int) $params['ingress_https_port']
        ));
    }

    public function check()
    {
        return $this->success($this->clusters->check(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        ));
    }

    public function overview()
    {
        return $this->success(['overview' => $this->clusters->overview(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        )]);
    }

    public function nodes()
    {
        return $this->success(['nodes' => $this->clusters->nodes(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        )]);
    }

    public function namespaces()
    {
        return $this->success(['namespaces' => $this->clusters->namespaces(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        )]);
    }

    public function nodeDetail()
    {
        $p = $this->validate(['name' => 'required|string|max:253']);
        return $this->success(['node' => $this->clusters->nodeDetail(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        )]);
    }

    public function pods()
    {
        return $this->success(['pods' => $this->clusters->pods(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function deployments()
    {
        return $this->success(['deployments' => $this->clusters->deployments(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function services()
    {
        return $this->success(['services' => $this->clusters->services(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function delete()
    {
        $this->clusters->delete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // ConfigMap
    // ---------------------------------------------------------------------
    public function configmaps()
    {
        return $this->success(['configmaps' => $this->clusters->configmaps(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function configMap()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['configmap' => $this->clusters->configMap(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function configMapCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'data' => 'nullable|array',
            'data.*.key' => 'required|string',
            'data.*.value' => 'nullable|string',
        ]);
        return $this->success(['configmap' => $this->clusters->configMapCreate($orgId, $clusterId, $p)]);
    }

    public function configMapUpdate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'data' => 'nullable|array',
            'data.*.key' => 'required|string',
            'data.*.value' => 'nullable|string',
        ]);
        return $this->success(['configmap' => $this->clusters->configMapUpdate($orgId, $clusterId, $p)]);
    }

    public function configMapDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->configMapDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Secret
    // ---------------------------------------------------------------------
    public function secrets()
    {
        return $this->success(['secrets' => $this->clusters->secrets(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function secret()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['secret' => $this->clusters->secret(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function secretCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'type' => 'nullable|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'data' => 'nullable|array',
            'data.*.key' => 'required|string',
            'data.*.value' => 'nullable|string',
        ]);
        return $this->success(['secret' => $this->clusters->secretCreate($orgId, $clusterId, $p)]);
    }

    public function secretUpdate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'data' => 'nullable|array',
            'data.*.key' => 'required|string',
            'data.*.value' => 'nullable|string',
        ]);
        return $this->success(['secret' => $this->clusters->secretUpdate($orgId, $clusterId, $p)]);
    }

    public function secretDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->secretDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Namespace
    // ---------------------------------------------------------------------
    public function namespaceDetail()
    {
        $p = $this->validate(['namespace' => 'required|string|max:63']);
        return $this->success($this->clusters->namespaceDetail(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $p['namespace']
        ));
    }

    public function namespaceResources()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'quota_enabled' => 'required|boolean',
            'quota_resources' => 'required|array',
            'quota_resources.cpu_reservation' => 'required|numeric|min:0|max:1000000',
            'quota_resources.cpu_limit' => 'required|numeric|min:0|max:1000000',
            'quota_resources.memory_reservation' => 'required|numeric|min:0|max:1073741824',
            'quota_resources.memory_limit' => 'required|numeric|min:0|max:1073741824',
            'storage_gib' => 'required|numeric|min:0|max:1073741824',
            'pods' => 'required|integer|min:0|max:1000000000',
            'services' => 'required|integer|min:0|max:1000000000',
            'persistent_volume_claims' => 'required|integer|min:0|max:1000000000',
            'limit_range_enabled' => 'required|boolean',
            'default_resources' => 'required|array',
            'default_resources.cpu_reservation' => 'required|numeric|min:0|max:1000000',
            'default_resources.cpu_limit' => 'required|numeric|min:0|max:1000000',
            'default_resources.memory_reservation' => 'required|numeric|min:0|max:1073741824',
            'default_resources.memory_limit' => 'required|numeric|min:0|max:1073741824',
        ]);
        return $this->success($this->clusters->namespaceResources(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p
        ));
    }

    public function namespaceCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'name' => 'required|string|max:63',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
        ]);
        return $this->success(['namespace' => $this->clusters->namespaceCreate($orgId, $clusterId, $p)]);
    }

    public function namespaceDelete()
    {
        $p = $this->validate(['name' => 'required|string|max:63']);
        $this->clusters->namespaceDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Deployment
    // ---------------------------------------------------------------------
    public function deployment()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['deployment' => $this->clusters->deployment(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function deploymentCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'replicas' => 'nullable|integer|min:0',
            'selector' => 'required|array',
            'selector.*.key' => 'required|string',
            'selector.*.value' => 'nullable|string',
            'containers' => 'required|array',
            'containers.*.name' => 'required|string',
            'containers.*.image' => 'required|string',
            'containers.*.ports' => 'nullable|array',
            'containers.*.ports.*.containerPort' => 'required|integer',
            'containers.*.ports.*.name' => 'nullable|string',
            'containers.*.ports.*.protocol' => 'nullable|string',
            'containers.*.env' => 'nullable|array',
            'containers.*.env.*.key' => 'required|string',
            'containers.*.env.*.value' => 'nullable|string',
            'containers.*.resources' => 'nullable|array',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentCreate($orgId, $clusterId, $p)]);
    }

    public function deploymentUpdate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'replicas' => 'nullable|integer|min:0',
            'selector' => 'required|array',
            'selector.*.key' => 'required|string',
            'selector.*.value' => 'nullable|string',
            'containers' => 'required|array',
            'containers.*.name' => 'required|string',
            'containers.*.image' => 'required|string',
            'containers.*.ports' => 'nullable|array',
            'containers.*.ports.*.containerPort' => 'required|integer',
            'containers.*.ports.*.name' => 'nullable|string',
            'containers.*.ports.*.protocol' => 'nullable|string',
            'containers.*.env' => 'nullable|array',
            'containers.*.env.*.key' => 'required|string',
            'containers.*.env.*.value' => 'nullable|string',
            'containers.*.resources' => 'nullable|array',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentUpdate($orgId, $clusterId, $p)]);
    }

    public function deploymentScale()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'replicas' => 'required|integer|min:0',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentScale(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            (int) $p['replicas']
        )]);
    }

    public function deploymentRestart()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentRestart(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function deploymentResources()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'containers' => 'required|array',
            'containers.*.name' => 'required|string',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentResources(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            (array) $p['containers']
        )]);
    }

    public function deploymentApply()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'containers' => 'nullable|array',
            'containers.*.name' => 'required|string',
            'volumes' => 'nullable|array',
            'volumes.*.name' => 'required|string',
            'hostNetwork' => 'nullable|boolean',
            'dnsPolicy' => 'nullable|in:ClusterFirst,ClusterFirstWithHostNet,Default,None',
            'dnsConfig' => 'nullable|array',
            'replicas' => 'nullable|integer|min:0',
            'labels' => 'nullable|array',
            'podLabels' => 'nullable|array',
        ]);
        return $this->success(['deployment' => $this->clusters->deploymentApply(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            $p
        )]);
    }

    public function deploymentDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->deploymentDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Service
    // ---------------------------------------------------------------------
    public function service()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['service' => $this->clusters->service(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function serviceCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'type' => 'required|string|in:ClusterIP,NodePort,LoadBalancer',
            'selector' => 'nullable|array',
            'selector.*.key' => 'required|string',
            'selector.*.value' => 'nullable|string',
            'target_kind' => 'nullable|string|in:Deployment,Pod',
            'target_name' => 'nullable|string|max:253',
            'ports' => 'required|array',
            'ports.*.name' => 'nullable|string',
            'ports.*.protocol' => 'nullable|string',
            'ports.*.port' => 'required|integer',
            'ports.*.targetPort' => 'required|integer',
            'ports.*.nodePort' => 'nullable|integer',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
        ]);
        return $this->success(['service' => $this->clusters->serviceCreate($orgId, $clusterId, $p)]);
    }

    public function serviceUpdate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'type' => 'required|string|in:ClusterIP,NodePort,LoadBalancer',
            'selector' => 'nullable|array',
            'selector.*.key' => 'required|string',
            'selector.*.value' => 'nullable|string',
            'target_kind' => 'nullable|string|in:Deployment,Pod',
            'target_name' => 'nullable|string|max:253',
            'ports' => 'required|array',
            'ports.*.name' => 'nullable|string',
            'ports.*.protocol' => 'nullable|string',
            'ports.*.port' => 'required|integer',
            'ports.*.targetPort' => 'required|integer',
            'ports.*.nodePort' => 'nullable|integer',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
        ]);
        return $this->success(['service' => $this->clusters->serviceUpdate($orgId, $clusterId, $p)]);
    }

    public function serviceDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->serviceDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Ingress
    // ---------------------------------------------------------------------
    public function ingresses()
    {
        return $this->success(['ingresses' => $this->clusters->ingresses(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function ingress()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['ingress' => $this->clusters->ingress(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function ingressCreate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer|min:1',
            'https_redirect' => 'nullable|boolean',
            'https_redirect_port' => 'nullable|integer|min:1|max:65535',
            'rules' => 'required|array',
            'rules.*.host' => 'nullable|string',
            'rules.*.paths' => 'required|array',
            'rules.*.paths.*.path' => 'required|string',
            'rules.*.paths.*.pathType' => 'required|string|in:Prefix,Exact,ImplementationSpecific',
            'rules.*.paths.*.serviceName' => 'required|string',
            'rules.*.paths.*.servicePort' => 'required|integer',
        ]);
        return $this->success(['ingress' => $this->clusters->ingressCreate($orgId, $clusterId, $p)]);
    }

    public function ingressUpdate()
    {
        $orgId = (int) Functions::getContextValue('org_id');
        $clusterId = $this->clusterId();
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'labels' => 'nullable|array',
            'labels.*.key' => 'required|string',
            'labels.*.value' => 'nullable|string',
            'tls_enabled' => 'nullable|boolean',
            'certificate_id' => 'nullable|integer|min:1',
            'https_redirect' => 'nullable|boolean',
            'https_redirect_port' => 'nullable|integer|min:1|max:65535',
            'rules' => 'required|array',
            'rules.*.host' => 'nullable|string',
            'rules.*.paths' => 'required|array',
            'rules.*.paths.*.path' => 'required|string',
            'rules.*.paths.*.pathType' => 'required|string|in:Prefix,Exact,ImplementationSpecific',
            'rules.*.paths.*.serviceName' => 'required|string',
            'rules.*.paths.*.servicePort' => 'required|integer',
        ]);
        return $this->success(['ingress' => $this->clusters->ingressUpdate($orgId, $clusterId, $p)]);
    }

    public function ingressDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->ingressDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Pod
    // ---------------------------------------------------------------------
    public function pod()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['pod' => $this->clusters->pod(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function podLogs()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'container' => 'nullable|string',
            'tail_lines' => 'nullable|integer|min:1',
        ]);
        return $this->success(['logs' => $this->clusters->podLogs(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            isset($p['container']) ? (string) $p['container'] : null,
            (int) ($p['tail_lines'] ?? 200)
        )]);
    }

    public function podDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->podDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    public function podTerminalTicket()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'container' => 'required|string|max:253',
            'command' => 'nullable|array',
        ]);
        return $this->success($this->terminal->issueTicket(
            (int) Functions::getLoginUser()->getId(),
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            $p['container'],
            isset($p['command']) && is_array($p['command']) ? $p['command'] : []
        ));
    }

    private function clusterId(): int
    {
        $params = $this->validate(['cluster_id' => 'required|integer|min:1']);
        return (int) $params['cluster_id'];
    }

    // ---------------------------------------------------------------------
    // StatefulSet
    // ---------------------------------------------------------------------
    public function statefulsets()
    {
        return $this->success(['statefulsets' => $this->clusters->statefulsets(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function statefulSet()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['statefulset' => $this->clusters->statefulSet(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function statefulSetScale()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
            'replicas' => 'required|integer|min:0',
        ]);
        return $this->success(['statefulset' => $this->clusters->statefulSetScale(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name'],
            (int) $p['replicas']
        )]);
    }

    public function statefulSetDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->statefulSetDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // DaemonSet
    // ---------------------------------------------------------------------
    public function daemonsets()
    {
        return $this->success(['daemonsets' => $this->clusters->daemonsets(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function daemonSet()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['daemonset' => $this->clusters->daemonSet(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function daemonSetDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->daemonSetDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Job
    // ---------------------------------------------------------------------
    public function jobs()
    {
        return $this->success(['jobs' => $this->clusters->jobs(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function job()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['job' => $this->clusters->job(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function jobDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->jobDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // CronJob
    // ---------------------------------------------------------------------
    public function cronjobs()
    {
        return $this->success(['cronjobs' => $this->clusters->cronjobs(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function cronjob()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['cronjob' => $this->clusters->cronjob(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function cronjobDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->cronjobDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // PersistentVolume (cluster scoped)
    // ---------------------------------------------------------------------
    public function persistentVolumes()
    {
        return $this->success(['persistentVolumes' => $this->clusters->persistentVolumes(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        )]);
    }

    public function persistentVolume()
    {
        $p = $this->validate(['name' => 'required|string|max:253']);
        return $this->success(['persistentVolume' => $this->clusters->persistentVolume(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        )]);
    }

    public function persistentVolumeDelete()
    {
        $p = $this->validate(['name' => 'required|string|max:253']);
        $this->clusters->persistentVolumeDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // PersistentVolumeClaim
    // ---------------------------------------------------------------------
    public function persistentVolumeClaims()
    {
        return $this->success(['persistentVolumeClaims' => $this->clusters->persistentVolumeClaims(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', '')
        )]);
    }

    public function persistentVolumeClaim()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        return $this->success(['persistentVolumeClaim' => $this->clusters->persistentVolumeClaim(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        )]);
    }

    public function persistentVolumeClaimDelete()
    {
        $p = $this->validate([
            'namespace' => 'required|string|max:63',
            'name' => 'required|string|max:253',
        ]);
        $this->clusters->persistentVolumeClaimDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['namespace'],
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // StorageClass (cluster scoped)
    // ---------------------------------------------------------------------
    public function storageClasses()
    {
        return $this->success(['storageClasses' => $this->clusters->storageClasses(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId()
        )]);
    }

    public function storageClass()
    {
        $p = $this->validate(['name' => 'required|string|max:253']);
        return $this->success(['storageClass' => $this->clusters->storageClass(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        )]);
    }

    public function storageClassDelete()
    {
        $p = $this->validate(['name' => 'required|string|max:253']);
        $this->clusters->storageClassDelete(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            $p['name']
        );
        return $this->success();
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------
    public function events()
    {
        return $this->success(['events' => $this->clusters->events(
            (int) Functions::getContextValue('org_id'),
            $this->clusterId(),
            (string) $this->request->input('namespace', ''),
            (string) $this->request->input('involved_kind', ''),
            (string) $this->request->input('involved_name', '')
        )]);
    }
}
