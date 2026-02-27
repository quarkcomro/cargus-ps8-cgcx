{* views/templates/admin/order_view.tpl *}
<div class="card mt-2">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <i class="material-icons">local_shipping</i> {l s='Cargus Shipping' mod='cargus'}
        </div>
        
        {if isset($cargus_remaining_deliveries)}
            <span class="badge {if $cargus_quota_low}badge-danger{else}badge-success{/if}" style="font-size: 14px; padding: 5px 10px;">
                <i class="material-icons" style="font-size: 14px; vertical-align: middle;">assignment</i>
                {l s='Abonament: %d expedieri rămase' sprintf=[$cargus_remaining_deliveries] mod='cargus'}
            </span>
        {/if}
    </div>
    
    <div class="card-body">
        {if !empty($cargus_awb)}
            <div class="alert alert-success">
                <p class="mb-0">
                    <strong>{l s='AWB Number:' mod='cargus'}</strong> {$cargus_awb|escape:'html':'UTF-8'}
                </p>
            </div>
            
            <div class="btn-group mb-3" role="group">
                <a href="{$cargus_print_link|escape:'html':'UTF-8'}" target="_blank" class="btn btn-primary">
                    <i class="material-icons">print</i> {l s='Print AWB (A6)' mod='cargus'}
                </a>
                <a href="{$cargus_tracking_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-outline-primary">
                    <i class="material-icons">visibility</i> {l s='Track Shipment' mod='cargus'}
                </a>
            </div>

            <hr>
            
            {* FORMULAR PENTRU RETUR / SCHIMBARE PUNCT *}
            <h4 style="font-size: 16px; margin-bottom: 15px;"><i class="material-icons">assignment_return</i> {l s='Generare AWB Retur / Schimbare' mod='cargus'}</h4>
            <form action="{$cargus_return_link|escape:'html':'UTF-8'}" method="post" class="form-horizontal">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="control-label">{l s='Ridicare Colete De La (Client):' mod='cargus'}</label>
                            <select name="pickup_type" class="form-control" onchange="document.getElementById('pickup_pudo_div').style.display = this.value === 'locker' ? 'block' : 'none';">
                                <option value="address">{l s='Adresa inițială a clientului' mod='cargus'}</option>
                                <option value="locker">{l s='Un Locker (PUDO)' mod='cargus'}</option>
                            </select>
                        </div>
                        <div class="form-group" id="pickup_pudo_div" style="display: none;">
                            <label class="control-label">{l s='ID Locker Client (Opțional):' mod='cargus'}</label>
                            <input type="text" name="pickup_pudo_id" class="form-control" placeholder="Ex: PUDO123">
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="control-label">{l s='Livrare Colete Către (Magazin):' mod='cargus'}</label>
                            <select name="delivery_type" class="form-control" onchange="document.getElementById('delivery_pudo_div').style.display = this.value === 'locker' ? 'block' : 'none';">
                                <option value="hq">{l s='Sediul Magazinului / Depozit' mod='cargus'}</option>
                                <option value="locker">{l s='Locker Magazin (PUDO)' mod='cargus'}</option>
                            </select>
                        </div>
                        <div class="form-group" id="delivery_pudo_div" style="display: none;">
                            <label class="control-label">{l s='ID Locker Magazin:' mod='cargus'}</label>
                            <input type="text" name="delivery_pudo_id" class="form-control" placeholder="Ex: PUDO456">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-warning mt-2">
                    <i class="material-icons">autorenew</i> {l s='Generează AWB Retur' mod='cargus'}
                </button>
            </form>
            
        {else}
            <div class="alert alert-info">
                {l s='No AWB has been generated for this order yet.' mod='cargus'}
            </div>
            <a href="{$cargus_generate_link|escape:'html':'UTF-8'}" class="btn btn-primary">
                <i class="material-icons">add_circle</i> {l s='Generate AWB' mod='cargus'}
            </a>
        {/if}
    </div>
</div>