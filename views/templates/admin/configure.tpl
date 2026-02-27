<div class="panel">
    <div class="panel-heading">
        <i class="icon-cogs"></i> {l s='Cargus V3 Premium Configuration' mod='cargus'}
    </div>

    <ul class="nav nav-tabs" id="cargusConfigTabs" role="tablist">
        <li class="active"><a href="#tab-api" role="tab" data-toggle="tab">1. {l s='CONT & API' mod='cargus'}</a></li>
        <li><a href="#tab-preferences" role="tab" data-toggle="tab">2. {l s='PREFERINȚE & SERVICII' mod='cargus'}</a></li>
        <li><a href="#tab-debugger" role="tab" data-toggle="tab">3. {l s='API DEBUGGER' mod='cargus'}</a></li>
    </ul>

    <div class="tab-content" style="margin-top: 20px;">
        
        <div class="tab-pane active" id="tab-api">
            <form action="" method="post" class="form-horizontal">
                <div class="form-group">
                    <label class="control-label col-lg-3 required">{l s='API URL' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <input type="text" name="CARGUS_API_URL" value="{$cargus_api_url|escape:'htmlall':'UTF-8'}" class="form-control" required />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3 required">{l s='Subscription Key' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <input type="text" name="CARGUS_SUBSCRIPTION_KEY" value="{$cargus_subscription_key|escape:'htmlall':'UTF-8'}" class="form-control" required />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3 required">{l s='User WebExpress' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <input type="text" name="CARGUS_USERNAME" value="{$cargus_username|escape:'htmlall':'UTF-8'}" class="form-control" required />
                    </div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3 required">{l s='Parolă' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <input type="password" name="CARGUS_PASSWORD" value="{$cargus_password|escape:'htmlall':'UTF-8'}" class="form-control" required />
                    </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" value="1" name="submitCargusConfig" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> {l s='Salvează Configurarea Modulului' mod='cargus'}
                    </button>
                </div>
            </form>
        </div>

        <div class="tab-pane" id="tab-preferences">
            <form action="" method="post" class="form-horizontal">
                
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Punct Ridicare Implicit' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_PICKUP_LOCATION" class="form-control">
                            <option value="">-- {l s='Selectează Locația' mod='cargus'} --</option>
                            {if $pickup_locations}
                                {foreach from=$pickup_locations item=location}
                                    <option value="{$location.LocationId|escape:'htmlall':'UTF-8'}" {if $cargus_pickup_location == $location.LocationId}selected{/if}>
                                        {$location.Name|escape:'htmlall':'UTF-8'} ({$location.LocalityName|escape:'htmlall':'UTF-8'})
                                    </option>
                                {/foreach}
                            {/if}
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Plan Tarifar' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_PRICE_PLAN" class="form-control">
                            <option value="">-- {l s='Selectează Plan' mod='cargus'} --</option>
                            {if $price_plans}
                                {foreach from=$price_plans item=plan}
                                    <option value="{$plan.PriceTableId|escape:'htmlall':'UTF-8'}" {if $cargus_price_plan == $plan.PriceTableId}selected{/if}>
                                        {$plan.Name|escape:'htmlall':'UTF-8'}
                                    </option>
                                {/foreach}
                            {/if}
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Serviciu Implicit' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_DEFAULT_SERVICE" class="form-control">
                            <option value="">-- {l s='Selectează Serviciu' mod='cargus'} --</option>
                            {foreach from=$cargus_services item=service}
                                <option value="{$service.id|escape:'htmlall':'UTF-8'}" {if $cargus_default_service == $service.id}selected{/if}>
                                    {$service.name|escape:'htmlall':'UTF-8'}
                                </option>
                            {/foreach}
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Plătitor Expediție' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_PAYER" class="form-control">
                            <option value="Expeditor" {if $cargus_payer == 'Expeditor'}selected{/if}>{l s='Expeditor' mod='cargus'}</option>
                            <option value="Destinatar" {if $cargus_payer == 'Destinatar'}selected{/if}>{l s='Destinatar' mod='cargus'}</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Tip Ramburs' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_COD_TYPE" class="form-control">
                            <option value="Numerar" {if $cargus_cod_type == 'Numerar'}selected{/if}>{l s='Numerar' mod='cargus'}</option>
                            <option value="Cont Colector" {if $cargus_cod_type == 'Cont Colector'}selected{/if}>{l s='Cont Colector' mod='cargus'}</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Tip Expediție' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <select name="CARGUS_SHIPMENT_TYPE" class="form-control">
                            <option value="Plic" {if $cargus_shipment_type == 'Plic'}selected{/if}>{l s='Plic' mod='cargus'}</option>
                            <option value="Colet" {if $cargus_shipment_type == 'Colet'}selected{/if}>{l s='Colet' mod='cargus'}</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Deschidere Colet' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="CARGUS_OPEN_PACKAGE" id="OP_ON" value="1" {if $cargus_open_package == 1}checked{/if}>
                            <label for="OP_ON">Yes</label>
                            <input type="radio" name="CARGUS_OPEN_PACKAGE" id="OP_OFF" value="0" {if $cargus_open_package == 0}checked{/if}>
                            <label for="OP_OFF">No</label>
                            <a class="slide-button btn"></a>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Livrare Sâmbăta' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="CARGUS_SATURDAY_DELIVERY" id="SD_ON" value="1" {if $cargus_saturday_delivery == 1}checked{/if}>
                            <label for="SD_ON">Yes</label>
                            <input type="radio" name="CARGUS_SATURDAY_DELIVERY" id="SD_OFF" value="0" {if $cargus_saturday_delivery == 0}checked{/if}>
                            <label for="SD_OFF">No</label>
                            <a class="slide-button btn"></a>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Asigurare' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <span class="switch prestashop-switch fixed-width-lg">
                            <input type="radio" name="CARGUS_INSURANCE" id="INS_ON" value="1" {if $cargus_insurance == 1}checked{/if}>
                            <label for="INS_ON">Yes</label>
                            <input type="radio" name="CARGUS_INSURANCE" id="INS_OFF" value="0" {if $cargus_insurance == 0}checked{/if}>
                            <label for="INS_OFF">No</label>
                            <a class="slide-button btn"></a>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Preț Bazic Standard' mod='cargus'}</label>
                    <div class="col-lg-6"><input type="text" name="CARGUS_BASIC_PRICE_STD" value="{$cargus_basic_price_std}" class="form-control" /></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Preț Bazic PUDO' mod='cargus'}</label>
                    <div class="col-lg-6"><input type="text" name="CARGUS_BASIC_PRICE_PUDO" value="{$cargus_basic_price_pudo}" class="form-control" /></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Preț KG Extra' mod='cargus'}</label>
                    <div class="col-lg-6"><input type="text" name="CARGUS_EXTRA_KG_PRICE" value="{$cargus_extra_kg_price}" class="form-control" /></div>
                </div>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Taxă Ramburs' mod='cargus'}</label>
                    <div class="col-lg-6"><input type="text" name="CARGUS_COD_FEE" value="{$cargus_cod_fee}" class="form-control" /></div>
                </div>

                <hr>
                <div class="form-group">
                    <label class="control-label col-lg-3">{l s='Prag Greutate Agabaritic (KG)' mod='cargus'}</label>
                    <div class="col-lg-6">
                        <input type="number" step="0.1" name="CARGUS_HEAVY_THRESHOLD" value="{$cargus_heavy_threshold}" class="form-control" />
                    </div>
                </div>

                <div class="panel-footer">
                    <button type="submit" value="1" name="submitCargusConfig" class="btn btn-default pull-right">
                        <i class="process-icon-save"></i> {l s='Salvează' mod='cargus'}
                    </button>
                </div>
            </form>
        </div>

        <div class="tab-pane" id="tab-debugger">
            <div class="row">
                <div class="col-lg-12">
                    <button class="btn btn-primary" id="btn-test-locations"><i class="icon-map-marker"></i> {l s='Test Locații' mod='cargus'}</button>
                </div>
            </div>
            <div class="row" style="margin-top: 20px;">
                <div class="col-lg-12">
                    <div id="cargus-console" style="background: #1e1e1e; color: #4caf50; padding: 15px; border-radius: 5px; height: 300px; overflow-y: auto; font-family: monospace;">
                        {l s='Sistem gata pentru testare...' mod='cargus'}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    var consoleDiv = document.getElementById('cargus-console');
    function appendLog(message, isError) {
        var p = document.createElement('div');
        p.style.color = isError ? '#ff4c4c' : '#4caf50';
        p.innerText = '[' + new Date().toLocaleTimeString() + '] > ' + message;
        consoleDiv.appendChild(p);
        consoleDiv.scrollTop = consoleDiv.scrollHeight;
    }
    document.getElementById('btn-test-locations').addEventListener('click', function(e) {
        e.preventDefault();
        appendLog('Se testează comunicarea cu API-ul...', false);
        fetch('{$cargus_ajax_link|escape:"javascript":"UTF-8"}&ajax=1&action=TestLocations', { method: 'POST' })
        .then(r => r.json()).then(d => appendLog(d.message, !d.success))
        .catch(e => appendLog('Eroare Rețea: ' + e.message, true));
    });
});
</script>
