<!doctype html>
<html lang="zh-CN">
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#1f2937;line-height:1.6">
  <div style="max-width:680px;margin:0 auto;padding:24px">
    <h2 style="margin:0 0 16px;color:{{ $recovery ? '#059669' : '#dc2626' }}">
      {{ $recovery ? 'Docker Service 已恢复' : 'Docker Service 运行告警' }}
    </h2>
    <p><strong>项目：</strong>{{ $project_title }}</p>
    <p><strong>告警：</strong>{{ $alert['title'] }}</p>
    <p><strong>状态：</strong>{{ $recovery ? '已恢复' : '异常' }}</p>
    <p><strong>详情：</strong>{{ $alert['message'] ?: '-' }}</p>
    <p><strong>累计检测：</strong>{{ $alert['occurrences'] }} 次</p>
    <p style="margin-top:24px;color:#6b7280;font-size:13px">此邮件由 CodeGalaxy Docker Swarm 运行监控自动发送。</p>
  </div>
</body>
</html>
