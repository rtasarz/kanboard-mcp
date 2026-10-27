<div class="panel">
    <h3><i class="fa fa-cog fa-fw"></i>&nbsp;<?= t('Model Context Protocol') ?></h3>
    
    <p><?= t('Control Kanboard via Model Context Protocol.') ?></p>
    
    <?php 
    // A freshly generated token is handed over through the session and shown
    // exactly once; only its hash is stored in the database.
    $newToken = session_get('mcpNewToken');
    session_remove('mcpNewToken');
    $activeToken = $this->task->mcpTokenModel->getActiveToken();
    $mcpUrl = $this->helper->url->base() . '?controller=ModelContextProtocolController&action=handle&plugin=ModelContextProtocol';
    ?>
    
    <?php if ($newToken !== null): ?>
    <div class="alert alert-success">
        <p><?= t('New token generated. Copy it now: it is stored hashed and will not be shown again.') ?></p>
    </div>
    <?php elseif ($activeToken !== null): ?>
    <p><?= t('A token was generated on %s. It cannot be displayed again; reset it to obtain a new one.', $this->dt->datetime((int) $activeToken['created_at'])) ?></p>
    <?php else: ?>
    <p><?= t('No MCP token has been generated yet. Generate a token to enable MCP access.') ?></p>
    <?php endif ?>

    <?php if ($newToken !== null || $activeToken !== null): ?>
    <div class="form-group">
        <label><strong><?= t('MCP Client Configuration') ?></strong></label>
        <textarea readonly 
                  class="form-control" 
                  style="font-family: monospace; height: 180px; resize: vertical; width: 100%;"
                  onclick="this.select();">{
  "mcpServers": {
    "kanboard": {
      "url": "<?= $this->text->e($mcpUrl) ?>",
      "headers": {
        "Authorization": "Bearer <?= $newToken !== null ? $this->text->e($newToken) : 'YOUR_TOKEN' ?>"
      }
    }
  }
}</textarea>
        <p class="form-help"><?= t('Copy this configuration to your MCP client settings (e.g., Claude Desktop app config). The token is sent as an Authorization header.') ?></p>
    </div>
    <?php endif ?>
    
    <div class="alert alert-info">
        <p><strong><?= t('Security Note:') ?></strong> <?= t('This URL provides full access to your Kanboard instance. Keep it secure.') ?></p>
    </div>

    <?= $this->url->link($activeToken !== null ? t('Reset token') : t('Generate Token'), 'ModelContextProtocolController', 'generateToken', array('plugin' => 'ModelContextProtocol'), true, 'btn btn-red') ?>
</div>
