<div class="crm-block crm-content-block">
  <h3>{ts domain='org.uschess.square'}Square Token Information{/ts}</h3>

  {if $error}
    <div class="messages error">
      {$error}
    </div>
  {/if}

  {if $contact_id}
    <table class="display">
      <tr>
        <th>{ts domain='org.uschess.square'}Contact ID{/ts}</th>
        <td>{$contact_id}</td>
      </tr>

      <tr>
        <th>{ts domain='org.uschess.square'}Square Customer ID{/ts}</th>
        <td>
          {if $square_customer_id}
            {$square_customer_id}
          {else}
            <em>{ts domain='org.uschess.square'}None stored{/ts}</em>
          {/if}
        </td>
      </tr>

      <tr>
        <th>{ts domain='org.uschess.square'}Square Card IDs{/ts}</th>
        <td>
          {if $card_ids|@count > 0}
            <ul class="crm-list">
              {foreach from=$card_ids item=cid}
                <li>{$cid}</li>
              {/foreach}
            </ul>
          {else}
            <em>{ts domain='org.uschess.square'}No stored cards{/ts}</em>
          {/if}
        </td>
      </tr>

      <tr>
        <th>{ts domain='org.uschess.square'}Subscription IDs{/ts}</th>
        <td>
          {if $subscription_ids|@count > 0}
            <ul class="crm-list">
              {foreach from=$subscription_ids item=sid}
                <li>{$sid}</li>
              {/foreach}
            </ul>
          {else}
            <em>{ts domain='org.uschess.square'}No subscriptions{/ts}</em>
          {/if}
        </td>
      </tr>
    </table>

    <div class="action-link">
      <a class="button" href="{crmURL p=$refreshUrl}">{ts domain='org.uschess.square'}Refresh from Square{/ts}</a>
      <a class="button" href="{crmURL p=$clearUrl}">{ts domain='org.uschess.square'}Clear Tokens{/ts}</a>
    </div>

  {else}
    <p>{ts domain='org.uschess.square'}No contact selected.{/ts}</p>
  {/if}
</div>
